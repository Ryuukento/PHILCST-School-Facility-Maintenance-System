<?php

namespace App\Services;

use App\Models\DamageReport;
use App\Models\MaintenanceReport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * TASK 50 — ReportService Extraction.
 *
 * THE ASYMMETRY THIS CLOSES (recorded by TASK 47): RepairRequest has a
 * dedicated RepairService, DamageReport has DamageReportService, Dispatch has
 * DispatchService — but the Maintenance Report domain, the one every other
 * module links back to, had no service at all. Its business logic lived
 * directly inside a ~1,300-line ReportController.
 *
 * WHAT THIS CLASS IS: the report-domain orchestration layer that used to be
 * private methods and inline blocks of ReportController. It owns creation,
 * update application, deletion, the status-transition map, structured
 * location resolution, and the report-domain activity logging and
 * notifications that are inseparable from those operations.
 *
 * WHAT THIS CLASS DELIBERATELY IS NOT:
 *
 *   - It is NOT an authorization service. Every authorization decision still
 *     belongs to ReportAuthorizationService (TASK 9/10/13/49). There is no
 *     role comparison anywhere in this file: the one role-derived input this
 *     class needs — "may this actor assign personnel at creation time" — is
 *     passed in as an already-decided boolean by the caller rather than
 *     re-derived here, precisely so this extraction does not create a second
 *     place where a role is interpreted.
 *
 *   - It is NOT a replacement for the domain services it orchestrates.
 *     Need Change approval remains owned by NeedChangeService (TASK 49,
 *     including its own service-level authorization check — untouched by this
 *     task). Asset-damage creation remains owned by DamageReportService
 *     (TASK 45); createAssetReport() below calls it in exactly the same order,
 *     with exactly the same payload, as ReportController did.
 *
 *   - It is NOT a "god service". Read paths (index/recent/show) stay in the
 *     controller: they are query construction feeding a JSON response shape,
 *     with no domain decisions to own.
 *
 * BEHAVIOUR CONTRACT: every method body below was moved from
 * ReportController without semantic modification. In particular:
 *
 *   - NO DB::transaction() is opened here, because the controller opened none.
 *     ReportController performed all of these writes outside any transaction
 *     (the only transactions in the report write path are the ones
 *     DamageReportService::createReport() and NeedChangeService::approve()
 *     open internally, and both are still entered from the same call sites in
 *     the same order). Introducing a transaction here would change rollback
 *     semantics, so this file deliberately contains none.
 *
 *   - Activity logging goes through ActivityLogService::logFromSession()
 *     rather than the controller's ->log($payload, $request). That is the
 *     convention every other service in app/Services already uses without
 *     exception, and it is behaviour-identical here: logFromSession() resolves
 *     the current request via request() (same instance the controller held, so
 *     the same ip_address/user_agent) and then merges
 *     `user_id => $payload['user_id'] ?? $authUser['user_id']` and
 *     `user_role => $payload['user_role'] ?? $authUser['role']` — both of
 *     which every payload below already sets explicitly from the very same
 *     $authUser array, so the merge is a no-op and the final attribute set is
 *     unchanged.
 *
 *   - Exceptions are not caught and reshaped here. ValidationException from
 *     resolveStructuredLocation() and DuplicateDamageReportException from
 *     DamageReportService both propagate to ReportController, which maps them
 *     to the same HTTP responses it always did.
 */
class ReportService
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        // SPRINT 5 / TASK 45 — asset-damage creation stays owned by this
        // service; createAssetReport() only translates /api/reports' input
        // shape into it, exactly as ReportController::storeWithAssetDetails()
        // did.
        private readonly DamageReportService $damageReportService,
        // TASK 20 — Assignment Notification Scoping: the same single
        // notification insert path used by DispatchService / RepairService /
        // DamageReportService.
        private readonly NotificationService $notificationService,
        // TASK 13/44 — consulted ONLY for departmentsMatch(), the codebase's
        // single department-comparison implementation, used to describe a
        // cross-department assignment in the audit trail. This class never
        // asks it an allow/deny question: authorization happens in the
        // controller, before any of these methods are reached.
        private readonly ReportAuthorizationService $reportAuthorizationService
    ) {
    }

    /**
     * Sprint 2 / Feature 2 — Maintenance Report Status Transition Enforcement.
     *
     * Moved verbatim from ReportController. Public because ReportController
     * still performs the "is this even a real status" enum check at the HTTP
     * boundary (a 422 'Invalid status value') before asking about the
     * transition, and both questions must be answered from the same list.
     */
    public const STATUSES = ['submitted', 'assigned', 'in_progress', 'completed', 'closed', 'cancelled'];

    /**
     * Mirrors the STATUS_TRANSITIONS map pattern already used by
     * DamageReportService and RepairService: a fixed allow-list of which
     * statuses each status may move to next. 'closed' and 'cancelled' are
     * terminal (no outgoing transitions) — closed is never reopenable, per
     * the Capstone Panel's controlled-workflow requirement.
     */
    private const STATUS_TRANSITIONS = [
        'submitted'   => ['assigned', 'in_progress', 'cancelled'],
        'assigned'    => ['in_progress', 'cancelled'],
        'in_progress' => ['completed', 'cancelled'],
        'completed'   => ['in_progress', 'closed'],
        'closed'      => [],
        'cancelled'   => [],
    ];

    /**
     * Validates a requested status change against self::STATUS_TRANSITIONS.
     * Same-status resubmission is always allowed (idempotent PATCH). Throws
     * ValidationException on any other unlisted from -> to jump.
     */
    /**
     * Status a new report starts in. Nobody can create a report that is
     * already finished (completed/closed/cancelled) — that would skip the
     * workflow and the completion-proof requirement. Head Maintenance may
     * start it directly as in_progress, but only with someone assigned;
     * anything else starts as submitted.
     */
    private function resolveCreationStatus(array $validated, bool $canAssignAtCreation): string
    {
        $requested = strtolower((string) ($validated['status'] ?? 'submitted'));

        if ($canAssignAtCreation && $requested === 'in_progress' && !empty($validated['assigned_to'])) {
            return 'in_progress';
        }

        return 'submitted';
    }

    public function assertValidStatusTransition(string $fromStatus, string $newStatus): void
    {
        if ($newStatus === $fromStatus) {
            return;
        }

        $allowedNext = self::STATUS_TRANSITIONS[$fromStatus] ?? [];
        if (!in_array($newStatus, $allowedNext, true)) {
            throw ValidationException::withMessages([
                'status' => "Cannot change status from {$fromStatus} to {$newStatus}.",
            ]);
        }
    }

    /**
     * TASK 38 — Create Report location validation against Buildings Overview.
     *
     * THE GAP THIS CLOSES: maintenance_reports.location is a single free-text
     * varchar(255) and store() only ever validated it as
     * ['nullable','string','max:255']. A reporter could therefore type
     * "Lourdes Building 1 lab 102" — or any room that does not exist at all —
     * and the report was accepted silently. Every one of the live reports
     * audited for this task had an unparseable location for exactly this
     * reason ('103', 'Probe Location', 'Lourdes V 4 floor room 102').
     *
     * SOURCE OF TRUTH: the buildings/floors/rooms tables that back Buildings
     * Overview — the same tables GET /api/buildings, GET
     * /api/buildings/{id}/floors and GET /api/rooms read. No new service,
     * table, column or lookup API is introduced, and nothing here writes to
     * Buildings Overview: an unknown room is rejected, never created.
     *
     * WHY THE ROOM'S OWN ROW IS THE ARBITER: rooms.building_id and
     * rooms.floor_id are two independent foreign keys. The database
     * guarantees each points at a real building and a real floor, but nothing
     * at the DB level guarantees that the floor belongs to that building. So
     * the building/floor agreement has to be asserted in application code —
     * which is exactly what RoomController::store() already does when a room
     * is created ("Floor not found or does not belong to the building"). This
     * applies the same rule at report time rather than inventing a second,
     * differently-shaped notion of a valid location.
     *
     * BACKWARD COMPATIBILITY: when none of the three IDs are supplied this
     * returns null and the caller leaves $validated['location'] exactly as it
     * was. That path is load-bearing — location is nullable in the schema
     * because the internal creators (dispatch / PM / asset-damage flows) never
     * set it, and a large number of existing API callers still post a plain
     * location string. Enforcing the triple unconditionally would have been a
     * new business rule, which this task explicitly must not invent.
     *
     * TASK 50 — moved verbatim from ReportController. Both call sites (create
     * and edit) still call this one implementation, so create/edit cannot
     * drift; the only change is that it now lives in the report domain
     * service instead of the HTTP controller.
     *
     * @throws ValidationException when the triple is incomplete or does not
     *         describe a room that is actually registered where it claims.
     */
    public function resolveStructuredLocation(array $validated): ?string
    {
        $buildingId = (int) ($validated['location_building_id'] ?? 0);
        $floorId    = (int) ($validated['location_floor_id'] ?? 0);
        $roomId     = (int) ($validated['location_room_id'] ?? 0);

        if ($buildingId === 0 && $floorId === 0 && $roomId === 0) {
            return null;
        }

        // Partial triples are rejected rather than "best-effort" resolved: a
        // room id alone would let a caller skip the building/floor agreement
        // check, which is the entire point of this validation.
        $missing = [];
        if ($buildingId === 0) {
            $missing['location_building_id'] = ['Please select a building.'];
        }
        if ($floorId === 0) {
            $missing['location_floor_id'] = ['Please select a floor.'];
        }
        if ($roomId === 0) {
            $missing['location_room_id'] = ['Please select a room.'];
        }
        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }

        $room = DB::table('rooms')
            ->leftJoin('buildings', 'rooms.building_id', '=', 'buildings.id')
            ->leftJoin('floors', 'rooms.floor_id', '=', 'floors.id')
            ->where('rooms.id', $roomId)
            ->select([
                'rooms.name',
                'rooms.building_id',
                'rooms.floor_id',
                'rooms.is_active',
                'buildings.name as building_name',
                'floors.name as floor_name',
                'floors.building_id as floor_building_id',
            ])
            ->first();

        // Deactivated rooms are kept forever so historical records still
        // resolve (see RoomController's include_inactive note), but they are
        // not a valid destination for a NEW report — the room picker does not
        // offer them, so accepting one here would be a bypass.
        if (!$room || !(int) $room->is_active) {
            throw ValidationException::withMessages([
                'location_room_id' => ['The selected room is not registered in Buildings Overview.'],
            ]);
        }

        if ((int) $room->building_id !== $buildingId) {
            throw ValidationException::withMessages([
                'location_room_id' => ['The selected room is not registered under the selected building.'],
            ]);
        }

        if ((int) $room->floor_id !== $floorId) {
            throw ValidationException::withMessages([
                'location_room_id' => ['The selected room is not registered on the selected floor.'],
            ]);
        }

        if ((int) $room->floor_building_id !== $buildingId) {
            throw ValidationException::withMessages([
                'location_floor_id' => ['The selected floor does not belong to the selected building.'],
            ]);
        }

        // Rebuilt from the database, never from client input, and joined with
        // ' / ' to match the "Building / Floor / Room" convention the report
        // detail view already uses for asset context.
        return implode(' / ', array_values(array_filter(
            [
                trim((string) ($room->building_name ?? '')),
                trim((string) ($room->floor_name ?? '')),
                trim((string) $room->name),
            ],
            static fn (string $part): bool => $part !== ''
        )));
    }

    /**
     * Problem Type — decides what belongs in maintenance_reports.problem_type_other.
     *
     * THE RULE, IN ONE PLACE: the free-text "Please specify the problem type"
     * value is stored only when the selected category is the designated
     * free-text one ('Other', named in config/maintenance_reports.php rather
     * than written as a bare literal here). For every other category this
     * returns null.
     *
     * Returning null rather than "leaving it alone" is the load-bearing part.
     * Without it, a report created as Other/"Broken flagpole" and later edited
     * to Electrical would keep "Broken flagpole" in problem_type_other forever
     * — a stale value contradicting the category beside it, which the detail
     * view would then render. The caller assigns this result unconditionally,
     * so switching away from Other actively clears the column.
     *
     * Deliberately mirrors resolveStructuredLocation() above: one
     * implementation, called from BOTH ReportController::store() and the CASE A
     * edit path, so create and edit cannot drift. It does NOT re-validate that
     * the text is present when the category is Other — that is a validation
     * concern and is owned by the `required_if` rule at the HTTP boundary, so
     * a missing value surfaces as a normal 422 field error rather than being
     * silently coerced to null here.
     */
    public function resolveProblemTypeOther(?string $problemType, ?string $problemTypeOther): ?string
    {
        $otherValue = (string) config('maintenance_reports.problem_type_other_value', 'Other');

        if ((string) $problemType !== $otherValue) {
            return null;
        }

        $trimmed = trim((string) $problemTypeOther);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * TASK 50 — the general (non-asset) creation path, moved out of
     * ReportController::store(). Persist -> audit -> notify, in that order,
     * with no surrounding transaction, exactly as before.
     *
     * $canAssignAtCreation is passed in rather than derived here. TASK 10
     * (Security Audit) established that Maintenance Staff may not assign
     * personnel or drive status transitions at creation time; that is a
     * role-derived permission, and this task must not create a second place
     * where roles are interpreted. The caller decides; this method only
     * applies the decision.
     */
    public function createGeneralReport(
        array $validated,
        array $authUser,
        bool $canAssignAtCreation,
        string $submitterName
    ): MaintenanceReport {
        $report = MaintenanceReport::query()->create([
            'title' => $validated['title'],
            'description' => $validated['description'],
            // Problem Type. Already restricted to the approved vocabulary by
            // the controller's `in:` rule, so no second check here.
            'problem_type' => $validated['problem_type'] ?? null,
            'problem_type_other' => $this->resolveProblemTypeOther(
                $validated['problem_type'] ?? null,
                $validated['problem_type_other'] ?? null
            ),
            'location' => $validated['location'] ?? null,
            'priority' => $validated['priority'] ?? 'medium',
            'status' => $this->resolveCreationStatus($validated, $canAssignAtCreation),
            'created_by' => (int)($authUser['user_id'] ?? 0),
            'assigned_to' => $canAssignAtCreation ? ($validated['assigned_to'] ?? null) : null,
            'department_id' => $validated['department_id'] ?? ($authUser['department_id'] ?? null),
            'due_date' => $validated['due_date'] ?? null,
        ]);

        // HIGH PRIORITY FIX 1 (Audit Trail) — the module='report' /
        // entity_type='report' convention established by
        // NeedChangeService::approve(). Placed immediately after the create(),
        // matching where ItemController logs CREATE_ITEM.
        $this->activityLogService->logFromSession([
            'user_id' => (int)($authUser['user_id'] ?? 0),
            'user_role' => $authUser['role'] ?? null,
            'action' => 'CREATE_REPORT',
            'module' => 'report',
            'entity_type' => 'report',
            'entity_id' => $report->report_id,
            'details' => 'Created maintenance report "' . $report->title . '" (Report #' . $report->report_id . ').',
            'meta' => [
                'status' => $report->status,
                'priority' => $report->priority,
                'department_id' => $report->department_id,
            ],
        ], $authUser);

        $this->notifyAdminsOfNewReport($report, $validated, $submitterName);

        return $report;
    }

    /**
     * SPRINT 5 — the asset-specific branch of report creation. Delegates
     * entirely to DamageReportService::createReport(), which (per Sprint 4)
     * already creates both the compatibility-layer DamageReport row AND its
     * paired MaintenanceReport row in one atomic transaction. This method's
     * only job is translating this endpoint's general-purpose input shape
     * (title/description/etc.) into that service's expected input, then
     * layering the caller's own title/description/location/assigned_to/
     * due_date onto the resulting MaintenanceReport (DamageReportService
     * always auto-derives a title from the item, since damage reports have
     * no title field of their own — /api/reports callers get to supply
     * theirs instead, same as the general path above).
     *
     * No business logic is duplicated: deployed-item validation,
     * duplicate-active-report prevention, image handling, history, activity
     * logging, and admin notification are all still performed exactly once,
     * inside DamageReportService::createReport() — this method does not
     * send its own "New Maintenance Report Submitted" notification for this
     * branch, to avoid double-notifying admins for the same event.
     *
     * TASK 50 — moved from ReportController::storeWithAssetDetails(). The one
     * structural change is that it now returns the DamageReport instead of a
     * JsonResponse, and lets DuplicateDamageReportException propagate: the
     * controller still catches it and still answers 409 with the same
     * ['duplicate' => ...] payload. Response shaping is an HTTP concern and
     * stays at the HTTP boundary.
     *
     * @throws \App\Exceptions\DuplicateDamageReportException
     */
    public function createAssetReport(array $validated, array $authUser, ?UploadedFile $damageImage = null): DamageReport
    {
        $damageReport = $this->damageReportService->createReport(
            [
                'item_id' => $validated['item_id'],
                'room_id' => $validated['room_id'],
                'department_id' => $validated['department_id'] ?? ($authUser['department_id'] ?? 0),
                'source_dispatch_id' => $validated['source_dispatch_id'] ?? null,
                'damage_description' => $validated['damage_description'] ?? $validated['description'],
                'severity_level' => $validated['severity_level'] ?? $validated['priority'] ?? 'medium',
                'repair_notes' => $validated['repair_notes'] ?? null,
                'override_duplicate' => $validated['override_duplicate'] ?? false,
            ],
            $authUser,
            $damageImage
        );

        $report = MaintenanceReport::query()->find($damageReport->report_id);
        if ($report) {
            $report->update([
                'title' => $validated['title'],
                'description' => $validated['description'],
                // Problem Type reaches the asset-linked branch the same way
                // title/description do: DamageReportService::createReport()
                // knows nothing about it (damage reports have no such field),
                // so it is layered on here. An asset-linked report is still a
                // maintenance report submitted through the same form, so it
                // carries the same required category.
                'problem_type' => $validated['problem_type'] ?? $report->problem_type,
                'problem_type_other' => $this->resolveProblemTypeOther(
                    $validated['problem_type'] ?? $report->problem_type,
                    $validated['problem_type_other'] ?? null
                ),
                'location' => $validated['location'] ?? $report->location,
                'assigned_to' => $validated['assigned_to'] ?? $report->assigned_to,
                'due_date' => $validated['due_date'] ?? $report->due_date,
            ]);

            // HIGH PRIORITY FIX 1 (Audit Trail) — this branch's underlying
            // maintenance_reports row is created inside
            // DamageReportService::createReport() (which already logs its
            // own CREATE_DAMAGE_REPORT entry for entity_type=damage_report).
            // That is a different entity_type/entity_id, so this is not a
            // duplicate: it is the one MaintenanceReport-specific entry this
            // fix is scoped to add, mirroring RepairService::fulfillReplacement()
            // logging both a 'dispatch' entry (via DispatchService) and its
            // own 'repair_request' entry for a single business event.
            $this->activityLogService->logFromSession([
                'user_id' => (int)($authUser['user_id'] ?? 0),
                'user_role' => $authUser['role'] ?? null,
                'action' => 'CREATE_REPORT',
                'module' => 'report',
                'entity_type' => 'report',
                'entity_id' => $report->report_id,
                'details' => 'Created maintenance report "' . $report->title . '" (Report #' . $report->report_id . ') from asset damage details.',
                'meta' => [
                    'status' => $report->status,
                    'damage_report_id' => $damageReport->id,
                ],
            ], $authUser);
        }

        return $damageReport;
    }

    /**
     * TASK 50 — the tail of ReportController::update(), moved verbatim.
     *
     * By the time this is called the controller has already: authorized the
     * actor (ReportAuthorizationService::canModifyReport() and, for Need
     * Change, canApproveNeedChange()/canRejectNeedChange()), validated every
     * input, enforced the status-transition map and the per-role status
     * allow-lists, and assembled $changes. This method performs the write and
     * everything that must follow it — audit entry, completion notification,
     * assignment notification — with no surrounding transaction, in the same
     * order as before.
     *
     * $previousStatus and $previousAssignedTo are captured by the caller
     * BEFORE any modification, which is what lets the notification checks
     * below distinguish a genuine transition from an idempotent resubmission
     * of the same value.
     */
    public function applyUpdate(
        MaintenanceReport $report,
        array $changes,
        array $authUser,
        string $previousStatus,
        ?int $previousAssignedTo
    ): void {
        $userId = (int) ($authUser['user_id'] ?? 0);

        $report->update($changes);

        $newStatus = $changes['status'] ?? null;

        // HIGH PRIORITY FIX 1 (Audit Trail) — a single log entry per request
        // (never more than one), with the action name reflecting which of
        // the requested event categories (Updated / Assigned / Status
        // Changed / Completed) this particular change actually represents.
        // No second logging mechanism, no per-field duplicate rows.
        $logAction = 'UPDATE_REPORT';
        $logDetails = 'Updated maintenance report #' . $report->report_id . '.';
        if ($newStatus !== null) {
            if (in_array($newStatus, ['completed', 'closed'], true)) {
                $logAction = 'COMPLETE_REPORT';
                $logDetails = 'Marked maintenance report #' . $report->report_id . ' as ' . $newStatus . '.';
            } elseif ($newStatus === 'assigned' && array_key_exists('assigned_to', $changes)) {
                $logAction = 'ASSIGN_REPORT';
                $logDetails = 'Assigned maintenance report #' . $report->report_id . ' to user #' . ($changes['assigned_to'] ?? 'none') . '.';
            } else {
                $logAction = 'UPDATE_REPORT_STATUS';
                $logDetails = 'Changed maintenance report #' . $report->report_id . ' status from ' . $previousStatus . ' to ' . $newStatus . '.';
            }
        }

        // TASK 44 (Cross-Department Assignment Warning) — record WHICH
        // departments were involved in an assignment, so an assignment made
        // across departments is reconstructable from the history that
        // already exists. This is purely descriptive: it runs AFTER
        // $report->update() has already succeeded and it never rejects
        // anything. Cross-department assignment stays allowed; authorization
        // remains solely ReportAuthorizationService::canModifyReport(), still
        // performed by the controller before this method is entered.
        // No new table and no second audit mechanism — the existing
        // ActivityLogService `meta` payload already carries arbitrary keys.
        $assignmentDepartmentMeta = [];
        if ($logAction === 'ASSIGN_REPORT') {
            $assignedUserId = $changes['assigned_to'] !== null ? (int) $changes['assigned_to'] : null;
            $reportDepartmentId = $report->department_id !== null ? (int) $report->department_id : null;
            $assigneeDepartmentId = null;
            if ($assignedUserId !== null) {
                $rawAssigneeDepartmentId = User::query()
                    ->where('user_id', $assignedUserId)
                    ->value('department_id');
                $assigneeDepartmentId = $rawAssigneeDepartmentId !== null ? (int) $rawAssigneeDepartmentId : null;
            }

            // Reuses the codebase's single department-comparison
            // implementation (TASK 13) rather than re-deriving "do these
            // match" here, and compares stable IDs, never display names.
            $isCrossDepartment = $assignedUserId !== null
                && !$this->reportAuthorizationService->departmentsMatch($reportDepartmentId, $assigneeDepartmentId);

            $assignmentDepartmentMeta = [
                'report_department_id' => $reportDepartmentId,
                'assignee_department_id' => $assigneeDepartmentId,
                'cross_department' => $isCrossDepartment,
            ];

            if ($isCrossDepartment) {
                $logDetails .= ' Cross-department assignment (report department #'
                    . ($reportDepartmentId ?? 'none')
                    . ' -> personnel department #'
                    . ($assigneeDepartmentId ?? 'none')
                    . ').';
            }
        }

        $this->activityLogService->logFromSession([
            'user_id' => $userId,
            'user_role' => $authUser['role'] ?? null,
            'action' => $logAction,
            'module' => 'report',
            'entity_type' => 'report',
            'entity_id' => $report->report_id,
            'details' => $logDetails,
            'meta' => array_merge(
                ['changed_fields' => array_keys($changes)],
                $newStatus !== null ? ['from_status' => $previousStatus, 'to_status' => $newStatus] : [],
                $assignmentDepartmentMeta
            ),
        ], $authUser);

        if (in_array($newStatus, ['completed', 'closed'], true) && $newStatus !== $previousStatus) {
            $this->notifyReportOwnerOfCompletion($report, $newStatus);
        }

        // TASK 20 — Assignment Notification Scoping: this is the ONLY
        // recipient for an assign/reassign event — never a department- or
        // admin-wide broadcast like notifyAdminsOfNewReport() above (that
        // "New Report Submitted" flow is unrelated and untouched). Fires
        // only on a genuine change of assignee, mirroring the same
        // "re-saving the same value must not re-notify" rule already used
        // by DispatchService::assignReleasePersonnel() and
        // RepairService::assignTechnician().
        if ($newStatus === 'assigned' && array_key_exists('assigned_to', $changes)) {
            $newAssignedTo = $changes['assigned_to'] !== null ? (int) $changes['assigned_to'] : null;
            if ($newAssignedTo !== null && $newAssignedTo !== $previousAssignedTo) {
                $this->notificationService->notify(
                    $newAssignedTo,
                    'You Have Been Assigned a Maintenance Report',
                    'You have been assigned to maintenance report #' . $report->report_id . ' (' . $report->title . ') at ' . ($report->location ?: 'N/A') . '.',
                    'report',
                    $report->report_id
                );
            }
        }
    }

    /**
     * TASK 50 — the delete + audit pair from ReportController::destroy(),
     * moved verbatim. Authorization (super_admin, or department-sharing
     * author — TASK 10) remains in the controller, ahead of this call.
     */
    public function deleteReport(MaintenanceReport $report, array $authUser): void
    {
        $reportId = $report->report_id;
        $reportTitle = $report->title;

        $report->delete();

        // HIGH PRIORITY FIX 1 (Audit Trail) — logged after the delete, using
        // attributes captured beforehand since the in-memory model instance
        // is used (no re-query of the now-deleted row), mirroring
        // ItemController::destroy()'s DELETE_ITEM logging placement.
        $this->activityLogService->logFromSession([
            'user_id' => (int) ($authUser['user_id'] ?? 0),
            'user_role' => $authUser['role'] ?? null,
            'action' => 'DELETE_REPORT',
            'module' => 'report',
            'entity_type' => 'report',
            'entity_id' => $reportId,
            'details' => 'Deleted maintenance report "' . $reportTitle . '" (Report #' . $reportId . ').',
        ], $authUser);
    }

    /**
     * Notifies every active administrator role that a new report was
     * submitted. Called only from the general creation path — see
     * createAssetReport()'s doc comment for why the asset path deliberately
     * does not call this (DamageReportService::createReport() already
     * notifies admins on its own terms).
     */
    private function notifyAdminsOfNewReport(MaintenanceReport $report, array $validated, string $submitterName): void
    {
        // TASK 52 — the report itself is already created and committed by the
        // time this runs (createGeneralReport() calls it after
        // MaintenanceReport::create(), with no surrounding DB::transaction()),
        // so an uncaught exception here would surface to the client as a 500
        // on an otherwise-successful report creation — risking a confused
        // resubmission/duplicate report. NotificationService::notify() (used
        // everywhere else in the app) already self-guards this exact way;
        // this raw-insert path predates that service and needs the same
        // isolation explicitly.
        try {
            $recipientRoles = ['super_admin', 'admin', 'maintenance_admin', 'admin_maintenance', 'department_admin'];
            $recipients = DB::table('users')
                ->select('user_id')
                ->where('status', 'active')
                ->whereIn(DB::raw('LOWER(role)'), $recipientRoles)
                ->pluck('user_id')
                ->all();

            if (!empty($recipients)) {
                $notificationRows = [];
                $hasReportIdColumn = Schema::hasColumn('notifications', 'report_id');
                foreach ($recipients as $recipientId) {
                    $row = [
                        'user_id' => (int)$recipientId,
                        'title' => 'New Maintenance Report Submitted',
                        'message' => $submitterName . ' submitted a new report: ' . $validated['title'] . ' (Report #' . $report->report_id . ')',
                        'is_read' => 0,
                        'created_at' => now(),
                        // TASK 17 — Notification Deep Linking: additive, does not
                        // replace the report_id column below.
                        'entity_type' => 'report',
                        'entity_id' => $report->report_id,
                    ];

                    if ($hasReportIdColumn) {
                        $row['report_id'] = $report->report_id;
                    }

                    $notificationRows[] = $row;
                }

                DB::table('notifications')->insert($notificationRows);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to notify admins of new report', [
                'report_id' => $report->report_id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->sendSuperAdminEmailForNewReport($report, $validated, $submitterName);
    }

    /**
     * Closes the "communication loop" gap identified in SYSTEM_FLOW_REVIEW.md §7:
     * the report owner previously received no notification when their report
     * reached a terminal state. Reuses the same raw notifications-table insert
     * pattern already established in notifyAdminsOfNewReport() above
     * (schema-flexible via Schema::hasColumn, since the notifications
     * migration doesn't declare a report_id column but some environments have
     * one added).
     */
    private function notifyReportOwnerOfCompletion(MaintenanceReport $report, string $finalStatus): void
    {
        $ownerId = (int) $report->created_by;
        if ($ownerId <= 0) {
            return;
        }

        // TASK 52 — same isolation reasoning as notifyAdminsOfNewReport()
        // above: applyUpdate() has already persisted the status change (and
        // logged it) by the time this runs, with no surrounding
        // DB::transaction(), so an uncaught exception here would turn an
        // already-successful status update into a client-visible 500.
        try {
            $statusLabel     = $finalStatus === 'closed' ? 'Closed' : 'Completed';
            $assignedName    = optional($report->assignee)->full_name ?: 'Unassigned';
            $completionDate  = $report->completed_date
                ? $report->completed_date->toDateString()
                : now()->toDateString();

            $row = [
                'user_id' => $ownerId,
                'title' => 'Report #' . $report->report_id . ' Marked as ' . $statusLabel,
                'message' => sprintf(
                    'Your maintenance report #%d (%s) at %s has been marked as %s. Assigned Staff: %s. Completion Date: %s.',
                    $report->report_id,
                    $report->title,
                    $report->location ?: 'N/A',
                    $statusLabel,
                    $assignedName,
                    $completionDate
                ),
                'is_read' => 0,
                'created_at' => now(),
                // TASK 17 — Notification Deep Linking: additive, does not
                // replace the report_id column below.
                'entity_type' => 'report',
                'entity_id' => $report->report_id,
            ];

            if (Schema::hasColumn('notifications', 'report_id')) {
                $row['report_id'] = $report->report_id;
            }

            DB::table('notifications')->insert($row);
        } catch (\Throwable $e) {
            Log::error('Failed to notify report owner of completion', [
                'report_id' => $report->report_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendSuperAdminEmailForNewReport(MaintenanceReport $report, array $validated, string $submitterName): void
    {
        try {
            $emailServicePath = base_path('public/backend/services/EmailService.php');
            if (!file_exists($emailServicePath)) {
                return;
            }

            require_once $emailServicePath;
            if (!class_exists('EmailService')) {
                return;
            }

            $superAdminRecipients = DB::table('users')
                ->select(['user_id', 'email', 'full_name'])
                ->where(function ($query): void {
                    $query->whereRaw("LOWER(TRIM(role)) = 'super_admin'")
                        ->orWhereRaw("LOWER(TRIM(role)) = 'super admin'");
                })
                ->where(function ($query): void {
                    $query->whereNull('status')
                        ->orWhereRaw("LOWER(TRIM(status)) = 'active'");
                })
                ->whereNotNull('email')
                ->where('email', '<>', '')
                ->get()
                ->map(static function ($row): array {
                    return [
                        'user_id' => (int)($row->user_id ?? 0),
                        'email' => strtolower(trim((string)($row->email ?? ''))),
                        'full_name' => (string)($row->full_name ?? 'Administrator'),
                    ];
                })
                ->filter(static function (array $row): bool {
                    return filter_var($row['email'], FILTER_VALIDATE_EMAIL) !== false;
                })
                ->values()
                ->all();

            $payload = [
                'report_id' => (int)$report->report_id,
                'title' => (string)($validated['title'] ?? ''),
                'description' => (string)($validated['description'] ?? ''),
                'location' => (string)($validated['location'] ?? ''),
                'priority' => (string)($validated['priority'] ?? 'medium'),
                'submitted_by' => $submitterName,
                'creator_name' => $submitterName,
            ];

            if (!empty($superAdminRecipients)) {
                // Must be fully qualified: this file is `namespace App\Services`,
                // and EmailService is a global (non-namespaced) class loaded via
                // the require_once above. An unqualified `EmailService::` here
                // resolves to the nonexistent `App\Services\EmailService`,
                // throwing `Error: Class "App\Services\EmailService" not found`
                // — which the catch (\Throwable $e) below silently swallowed and
                // logged as "non-fatal", meaning this email was never actually
                // being sent.
                \EmailService::sendNewReportNotification($payload, $superAdminRecipients);
            }
        } catch (\Throwable $e) {
            Log::warning('Non-fatal Administrator email notification error', [
                'report_id' => (int)$report->report_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
