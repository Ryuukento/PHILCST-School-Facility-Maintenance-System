<?php

namespace App\Services;

use App\Exceptions\DuplicateDamageReportException;
use App\Models\DamageReport;
use App\Models\DamageReportHistory;
use App\Models\Dispatch;
use App\Models\InventoryTransaction;
use App\Models\Item;
use App\Models\MaintenanceReport;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DamageReportService
{
    private const ACTIVE_STATUSES = ['pending', 'under_review', 'repairing'];

    // Normalized damage_description strings scoring at or above this
    // similar_text() percentage are treated as describing the same issue.
    private const DUPLICATE_SIMILARITY_THRESHOLD = 70.0;

    // Per BUSINESS_RULES.md §7: submitted -> under review -> in progress/repairing -> resolved/replaced -> closed.
    // closed is terminal; repaired/replaced may only be closed, never reopened.
    private const STATUS_TRANSITIONS = [
        'pending'      => ['under_review', 'repairing', 'closed'],
        'under_review' => ['repairing', 'repaired', 'replaced', 'closed'],
        'repairing'    => ['repaired', 'replaced', 'closed'],
        'repaired'     => ['closed'],
        'replaced'     => ['closed'],
        'closed'       => [],
    ];

    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly NotificationService $notificationService,
        // SPRINT 5: status-sync only, per SPRINT_5_PRIMARY_WORKFLOW_MIGRATION.md
        // §4. Auto-resolved by the container like the two dependencies above;
        // no caller of this service needs to change.
        private readonly MaintenanceReportSyncService $maintenanceReportSyncService,
        // TASK 52 — notify once on a genuine NORMAL -> LOW/OUT_OF_STOCK
        // transition for the replacement item deducted by the 'replaced'
        // branch of updateStatus() below. Mirrors the previousStatus-before/
        // handleStatusChange-after pattern Task 49 established elsewhere —
        // this was another 'deploy'-creating flow missing it.
        private readonly InventoryLowStockNotifier $inventoryLowStockNotifier
    ) {
    }

    public function normalizeRole(string $role): string
    {
        return RoleNormalizerService::normalizeWithStaffDefault($role);
    }

    private function notifyAdmins(string $title, string $message, ?string $entityType = null, ?int $entityId = null): void
    {
        $adminRoles = RoleNormalizerService::rawValuesFor(['maintenance_admin', 'super_admin']);
        $adminIds = User::query()
            ->where('status', 'active')
            ->whereIn('role', $adminRoles)
            ->pluck('user_id');

        foreach ($adminIds as $adminId) {
            $this->notificationService->notify((int) $adminId, $title, $message, $entityType, $entityId);
        }
    }

    public function listReports(array $filters, array $authUser): LengthAwarePaginator
    {
        $role = $this->normalizeRole((string)($authUser['role'] ?? ''));
        $userId = (int)($authUser['user_id'] ?? 0);

        // TASK 45 (Damage Report Role Redesign) — a Damage Report is an
        // asset-damage view OVER the primary maintenance workflow, not a second
        // workflow of its own. The list therefore has to show which maintenance
        // report each damage case belongs to and who is assigned to it. Both
        // facts already exist and are reachable through relationships that were
        // already defined (DamageReport::report() since Sprint 4, and
        // MaintenanceReport::assignee()); they simply were never eager-loaded
        // here, which is why the page could only ever render asset columns.
        //
        // These are eager loads, so they cost a small fixed number of queries
        // for the whole page rather than one per row — no N+1 — and no damage
        // data is copied into a new table. `report` is nullable (legacy rows
        // predating Sprint 4 have report_id = null), so every consumer must
        // treat it as optional.
        $query = DamageReport::query()->with([
            'item:id,name,brand,model',
            'room:id,name,building_id',
            'room.building:id,name',
            'department:department_id,name',
            'reporter:user_id,full_name',
            'replacementItem:id,name,brand,model',
            'report:report_id,title,status,priority,assigned_to,department_id,created_at',
            'report.assignee:user_id,full_name,department_id',
        ]);

        if (!in_array($role, ['super_admin', 'maintenance_admin', 'maintenance_staff'], true)) {
            $query->where('reported_by', $userId);
        }

        if (!empty($filters['status'])) {
            $query->where('status', (string)$filters['status']);
        }

        if (!empty($filters['severity_level'])) {
            $query->where('severity_level', (string)$filters['severity_level']);
        }

        if (!empty($filters['department_id'])) {
            $query->where('department_id', (int)$filters['department_id']);
        }

        if (!empty($filters['room_id'])) {
            $query->where('room_id', (int)$filters['room_id']);
        }

        if (!empty($filters['q'])) {
            $q = strtolower(trim((string)$filters['q']));
            $query->where(function ($builder) use ($q): void {
                $builder->whereRaw('LOWER(damage_report_code) LIKE ?', ["%{$q}%"])
                    ->orWhereRaw('LOWER(damage_description) LIKE ?', ["%{$q}%"])
                    ->orWhereHas('item', function ($itemQ) use ($q): void {
                        $itemQ->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"])
                            ->orWhereRaw('LOWER(COALESCE(brand,\'\')) LIKE ?', ["%{$q}%"])
                            ->orWhereRaw('LOWER(COALESCE(model,\'\')) LIKE ?', ["%{$q}%"]);
                    })
                    ->orWhereHas('room', function ($roomQ) use ($q): void {
                        $roomQ->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"]);
                    })
                    ->orWhereHas('department', function ($deptQ) use ($q): void {
                        $deptQ->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"]);
                    });
            });
        }

        // INVENTORY REPORTS SEMESTRAL/YEARLY FIX — additive date-range filter
        // for the reporting module. `damage_reports` has no dedicated
        // transaction date column, only timestamps(), so `created_at` is the
        // correct field. Uses the project's established whereDate()
        // convention (see AnalyticsService/ReportController) so date-only
        // comparisons stay correct against a datetime column. Omitted by
        // default so existing callers are unaffected.
        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', (string)$filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', (string)$filters['date_to']);
        }

        $perPage = max(1, min(200, (int)($filters['per_page'] ?? 20)));

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    public function createReport(array $data, array $authUser, ?UploadedFile $imageFile = null): DamageReport
    {
        $reporterId = (int)($authUser['user_id'] ?? 0);
        if ($reporterId <= 0) {
            throw ValidationException::withMessages([
                'reported_by' => 'Authenticated user is required to create a damage report.',
            ]);
        }

        $itemId = (int)($data['item_id'] ?? 0);
        $roomId = (int)($data['room_id'] ?? 0);
        $departmentId = (int)($data['department_id'] ?? 0);
        $sourceDispatchId = isset($data['source_dispatch_id']) && $data['source_dispatch_id'] !== '' ? (int)$data['source_dispatch_id'] : null;

        if ($itemId <= 0 || $roomId <= 0 || $departmentId <= 0) {
            throw ValidationException::withMessages([
                'item_id' => 'Item, room, and department are required.',
            ]);
        }

        $this->validateDeployedItem($itemId, $roomId, $sourceDispatchId);

        $description = trim((string)($data['damage_description'] ?? ''));
        $overrideDuplicate = filter_var($data['override_duplicate'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $imagePath = null;
        if ($imageFile !== null) {
            $imagePath = $this->storeImage($imageFile);
        }

        return DB::transaction(function () use ($itemId, $roomId, $departmentId, $sourceDispatchId, $description, $overrideDuplicate, $data, $reporterId, $imagePath, $authUser): DamageReport {
            // Row-locked (FOR UPDATE) so two concurrent submissions for the same
            // item/room/department can't both pass this check before either commits.
            $duplicate = $this->findPotentialDuplicate($itemId, $roomId, $departmentId, $description, true);
            if ($duplicate !== null && !$overrideDuplicate) {
                throw new DuplicateDamageReportException($this->formatDuplicate($duplicate));
            }

            $code = 'DMG-' . now()->format('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

            $report = DamageReport::query()->create([
                'damage_report_code' => $code,
                'item_id' => $itemId,
                'room_id' => $roomId,
                'department_id' => $departmentId,
                'source_dispatch_id' => $sourceDispatchId,
                'damage_description' => $description,
                'severity_level' => (string)($data['severity_level'] ?? 'medium'),
                'reported_by' => $reporterId,
                'status' => 'pending',
                'image_path' => $imagePath,
                'repair_notes' => isset($data['repair_notes']) ? trim((string)$data['repair_notes']) : null,
            ]);

            // SPRINT 4: originate the linked maintenance_reports row here, per
            // SPRINT_4_WORKFLOW_MIGRATION.md — new Damage Reports now also
            // create the central maintenance_reports entity and stamp its
            // report_id back onto this row, using the report_category/item_id/
            // source_dispatch_id columns Sprint 1 added specifically for this
            // ("future 'affected asset/item reference' for a
            // repair_replacement-category report"). This is additive only:
            // the DamageReport row, its validation, and its response shape
            // are unchanged; a second, linked row is created alongside it in
            // the same transaction, so either both are created or neither is.
            $item = Item::query()->find($itemId);
            $mappedPriority = in_array($report->severity_level, ['low', 'medium', 'high', 'critical'], true)
                ? $report->severity_level
                : 'medium';

            $maintenanceReport = MaintenanceReport::query()->create([
                'title' => 'Damage Report: ' . ($item->name ?? ('Item #' . $itemId)),
                'description' => $report->damage_description,
                'priority' => $mappedPriority,
                'status' => 'submitted',
                'created_by' => $reporterId,
                'department_id' => $departmentId,
                'report_category' => 'repair_replacement',
                'item_id' => $itemId,
                'source_dispatch_id' => $sourceDispatchId,
            ]);

            $report->report_id = $maintenanceReport->report_id;
            $report->save();

            $this->recordHistory($report->id, 'created', null, 'pending', $report->repair_notes, [
                'has_image' => $imagePath !== null,
                'report_id' => $maintenanceReport->report_id,
            ], $reporterId);

            if ($imagePath !== null) {
                $this->recordHistory($report->id, 'image_uploaded', 'pending', 'pending', 'Initial damage image uploaded.', [
                    'image_path' => $imagePath,
                ], $reporterId);
            }

            $this->activityLogService->logFromSession([
                'user_id' => $reporterId,
                'user_role' => $authUser['role'] ?? null,
                'action' => 'CREATE_DAMAGE_REPORT',
                'module' => 'damage_reporting',
                'entity_type' => 'damage_report',
                'entity_id' => $report->id,
                'details' => 'Created damage report ' . $report->damage_report_code . '.',
                'meta' => [
                    'severity_level' => $report->severity_level,
                    'source_dispatch_id' => $sourceDispatchId,
                    'report_id' => $maintenanceReport->report_id,
                ],
            ], $authUser);

            $this->notifyAdmins(
                'New Damage Report Submitted',
                'A new damage report ' . $report->damage_report_code . ' has been submitted and requires review.',
                'damage_report',
                $report->id
            );

            return $report;
        });
    }

    public function updateStatus(DamageReport $report, array $data, array $authUser): DamageReport
    {
        $userId = (int)($authUser['user_id'] ?? 0);
        if ($userId <= 0) {
            throw ValidationException::withMessages([
                'user' => 'Authenticated user is required.',
            ]);
        }

        $nextStatus = strtolower(trim((string)($data['status'] ?? $report->status)));
        $allowedStatuses = ['pending', 'under_review', 'repairing', 'repaired', 'replaced', 'closed'];
        if (!in_array($nextStatus, $allowedStatuses, true)) {
            throw ValidationException::withMessages([
                'status' => 'Invalid status.',
            ]);
        }

        $fromStatus = strtolower(trim((string)$report->status));
        if ($nextStatus !== $fromStatus) {
            $allowedNext = self::STATUS_TRANSITIONS[$fromStatus] ?? [];
            if (!in_array($nextStatus, $allowedNext, true)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot change status from {$fromStatus} to {$nextStatus}.",
                ]);
            }
        }

        $previousNotes = (string)($report->repair_notes ?? '');
        $repairNotes = array_key_exists('repair_notes', $data)
            ? trim((string)($data['repair_notes'] ?? ''))
            : $report->repair_notes;

        return DB::transaction(function () use ($report, $nextStatus, $repairNotes, $previousNotes, $data, $userId, $authUser): DamageReport {
            $fromStatus = $report->status;
            $meta = [];

            if ($nextStatus === 'replaced') {
                $replacementItemId = (int)($data['replacement_item_id'] ?? 0);
                $replacementQty = max(1, (int)($data['replacement_quantity'] ?? 1));

                if ($replacementItemId <= 0) {
                    throw ValidationException::withMessages([
                        'replacement_item_id' => 'Replacement item is required when marking as replaced.',
                    ]);
                }

                if ($report->replacement_transaction_id === null) {
                    $replacementItem = Item::query()->find($replacementItemId);
                    if (!$replacementItem) {
                        throw ValidationException::withMessages([
                            'replacement_item_id' => 'Replacement item not found.',
                        ]);
                    }

                    // TASK 52 — captured BEFORE creating the InventoryTransaction
                    // below, for the same reason documented in
                    // InventoryAdjustmentService::adjust(): the Observer's
                    // created() hook re-derives and saves Item.status
                    // synchronously as part of that create() call.
                    $previousReplacementItemStatus = $replacementItem->status;

                    $tx = InventoryTransaction::query()->create([
                        'item_id' => $replacementItemId,
                        // SPRINT 4: was hardcoded null; now passes through the
                        // report_id stamped on this damage report at creation
                        // time (Sprint 4), the same way NeedChangeService and
                        // DispatchService::releaseDispatch (Sprint 3) already
                        // populate it. Still null for any damage report
                        // created before this sprint shipped.
                        'report_id' => $report->report_id,
                        'room_id' => $report->room_id,
                        'transaction_type' => 'deploy',
                        'quantity' => $replacementQty,
                        'reference_note' => 'Damage replacement for ' . $report->damage_report_code,
                        'performed_by' => $userId,
                    ]);

                    $report->replacement_item_id = $replacementItemId;
                    $report->replacement_quantity = $replacementQty;
                    $report->replacement_transaction_id = $tx->id;
                    $report->replaced_by = $userId;
                    $report->replaced_at = now();

                    $meta['replacement_transaction_id'] = $tx->id;
                    $meta['replacement_item_id'] = $replacementItemId;
                    $meta['replacement_quantity'] = $replacementQty;

                    // TASK 52 — fires only on a genuine NORMAL ->
                    // LOW/OUT_OF_STOCK transition. refresh() pulls back the
                    // status the Observer already derived and saved as part
                    // of the InventoryTransaction::create() call above.
                    $replacementItem->refresh();
                    $this->inventoryLowStockNotifier->handleStatusChange(
                        (int) $replacementItem->id,
                        $replacementItem->name,
                        $previousReplacementItemStatus,
                        $replacementItem->status
                    );
                }
            }

            if ($nextStatus === 'closed') {
                $report->closed_at = now();
            }

            $report->status = $nextStatus;
            $report->repair_notes = $repairNotes !== '' ? $repairNotes : null;
            $report->save();

            // SPRINT 5: propagate this status change to the linked
            // maintenance_reports row (Sprint 4's report_id), so it keeps
            // reflecting the current lifecycle. No-op for legacy reports
            // with report_id === null.
            $this->maintenanceReportSyncService->syncFromDamageReport($report);

            if ($fromStatus !== $nextStatus) {
                $this->recordHistory(
                    $report->id,
                    'status_changed',
                    $fromStatus,
                    $nextStatus,
                    $report->repair_notes,
                    $meta,
                    $userId
                );
            } elseif ($repairNotes !== $previousNotes) {
                $this->recordHistory(
                    $report->id,
                    'repair_note_updated',
                    $fromStatus,
                    $nextStatus,
                    $report->repair_notes,
                    $meta,
                    $userId
                );
            }

            $this->activityLogService->logFromSession([
                'user_id' => $userId,
                'user_role' => $authUser['role'] ?? null,
                'action' => $nextStatus === 'replaced' ? 'REPLACEMENT_ACTION' : 'UPDATE_DAMAGE_REPORT',
                'module' => $nextStatus === 'replaced' ? 'replacement' : 'damage_reporting',
                'entity_type' => 'damage_report',
                'entity_id' => $report->id,
                'details' => $nextStatus === 'replaced'
                    ? 'Marked damage report ' . $report->damage_report_code . ' as replaced.'
                    : 'Updated damage report ' . $report->damage_report_code . ' to status ' . $nextStatus . '.',
                'meta' => $meta + [
                    'from_status' => $fromStatus,
                    'to_status' => $nextStatus,
                ],
                'dedupe_window_seconds' => 1,
            ], $authUser);

            if ($nextStatus === 'replaced') {
                $this->notificationService->notify(
                    (int) $report->reported_by,
                    'Damage Report Replacement Update',
                    'Your damage report ' . $report->damage_report_code . ' has been marked as replaced.',
                    'damage_report',
                    $report->id
                );
            } elseif (in_array($nextStatus, ['repaired', 'closed'], true) && $fromStatus !== $nextStatus) {
                $this->notificationService->notify(
                    (int) $report->reported_by,
                    'Damage Report Update',
                    'Your damage report ' . $report->damage_report_code . ' is now ' . $nextStatus . '.',
                    'damage_report',
                    $report->id
                );
            }

            return $report;
        });
    }

    public function getHistories(DamageReport $report)
    {
        return $report->histories()->with('changedBy:user_id,full_name')->orderByDesc('created_at')->get();
    }

    private function validateDeployedItem(int $itemId, int $roomId, ?int $sourceDispatchId): void
    {
        $item = Item::query()->where('id', $itemId)->first();
        if (!$item) {
            throw ValidationException::withMessages([
                'item_id' => 'Selected item does not exist.',
            ]);
        }

        // TASK 37 — since 37.2, a room-deployed unit is its own `room_asset`
        // row (quantity=1) rather than a stock item with an InventoryTransaction
        // deploy record. Validate against whichever shape the id turns out to be.
        if ($item->item_type === 'room_asset') {
            if ((int)$item->room_id !== $roomId) {
                throw ValidationException::withMessages([
                    'item_id' => 'Selected asset is not located in the selected room.',
                ]);
            }
        } else {
            $hasDeployment = InventoryTransaction::query()
                ->where('item_id', $itemId)
                ->where('transaction_type', 'deploy')
                ->where(function ($query) use ($roomId): void {
                    $query->where('room_id', $roomId)->orWhereNull('room_id');
                })
                ->exists();

            if (!$hasDeployment) {
                throw ValidationException::withMessages([
                    'item_id' => 'Only deployed items can be reported as damaged.',
                ]);
            }
        }

        if ($sourceDispatchId !== null && $sourceDispatchId > 0) {
            $dispatch = Dispatch::query()->with('items')->find($sourceDispatchId);
            if (!$dispatch || $dispatch->status !== 'released') {
                throw ValidationException::withMessages([
                    'source_dispatch_id' => 'Source dispatch is invalid or not released.',
                ]);
            }

            $hasItem = $dispatch->items->contains(static function ($row) use ($itemId): bool {
                return (int)$row->item_id === $itemId;
            });

            if (!$hasItem) {
                throw ValidationException::withMessages([
                    'source_dispatch_id' => 'Selected dispatch does not contain the selected item.',
                ]);
            }
        }
    }

    /**
     * Pre-submit check used by the check-duplicate endpoint, so the UI can warn
     * before the user fills out and submits the full form. Unlocked — this is
     * advisory only; the authoritative, row-locked check runs inside createReport().
     */
    public function checkDuplicate(array $data): ?array
    {
        $itemId = (int)($data['item_id'] ?? 0);
        $roomId = (int)($data['room_id'] ?? 0);
        $departmentId = (int)($data['department_id'] ?? 0);
        $description = trim((string)($data['damage_description'] ?? ''));

        if ($itemId <= 0 || $roomId <= 0 || $departmentId <= 0) {
            return null;
        }

        $duplicate = $this->findPotentialDuplicate($itemId, $roomId, $departmentId, $description);

        return $duplicate !== null ? $this->formatDuplicate($duplicate) : null;
    }

    /**
     * Finds an active damage report for the same item+room+department whose
     * damage_description normalizes to the same (or highly similar) text —
     * i.e. the same reported issue, not just any other active report against
     * that item/room. Multiple distinct physical units of the same catalog
     * item can now be deployed to one room (Task 37 asset tracking), so two
     * genuinely different issues on two different units must not collide.
     */
    private function findPotentialDuplicate(
        int $itemId,
        int $roomId,
        int $departmentId,
        string $description,
        bool $lock = false
    ): ?DamageReport {
        $query = DamageReport::query()
            ->where('item_id', $itemId)
            ->where('room_id', $roomId)
            ->where('department_id', $departmentId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->orderByDesc('created_at');

        if ($lock) {
            $query->lockForUpdate();
        }

        $normalized = $this->normalizeDescription($description);
        if ($normalized === '') {
            return $query->first();
        }

        foreach ($query->get() as $candidate) {
            $candidateNormalized = $this->normalizeDescription((string) $candidate->damage_description);
            if ($candidateNormalized === '') {
                continue;
            }

            similar_text($normalized, $candidateNormalized, $percent);
            if ($normalized === $candidateNormalized || $percent >= self::DUPLICATE_SIMILARITY_THRESHOLD) {
                return $candidate;
            }
        }

        return null;
    }

    private function normalizeDescription(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    private function formatDuplicate(DamageReport $duplicate): array
    {
        return [
            'id' => $duplicate->id,
            'damage_report_code' => $duplicate->damage_report_code,
            'status' => $duplicate->status,
            'severity_level' => $duplicate->severity_level,
            'damage_description' => $duplicate->damage_description,
            'reported_at' => optional($duplicate->created_at)->toIso8601String(),
        ];
    }

    private function storeImage(UploadedFile $image): string
    {
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $ext = strtolower((string)$image->getClientOriginalExtension());
        if (!in_array($ext, $allowedExt, true)) {
            throw ValidationException::withMessages([
                'damage_image' => 'Only JPG, PNG, WEBP, or GIF images are allowed.',
            ]);
        }

        $targetDir = public_path('frontend/uploads/damage-reports');
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw ValidationException::withMessages([
                'damage_image' => 'Unable to prepare upload directory.',
            ]);
        }

        $fileName = 'damage-' . now()->format('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $image->move($targetDir, $fileName);

        return '/frontend/uploads/damage-reports/' . $fileName;
    }

    private function recordHistory(
        int $damageReportId,
        string $actionType,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $notes,
        array $meta,
        int $changedBy
    ): void {
        DamageReportHistory::query()->create([
            'damage_report_id' => $damageReportId,
            'action_type' => $actionType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'notes' => $notes,
            'meta_json' => !empty($meta) ? json_encode($meta) : null,
            'changed_by' => $changedBy,
            'created_at' => now(),
        ]);
    }
}
