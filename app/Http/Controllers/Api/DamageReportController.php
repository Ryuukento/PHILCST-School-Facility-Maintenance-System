<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DuplicateDamageReportException;
use App\Http\Controllers\Controller;
use App\Models\DamageReport;
use App\Models\MaintenanceReport;
use App\Services\ActivityLogService;
use App\Services\DamageReportService;
use App\Services\ReportArchiveService;
use App\Services\ReportAuthorizationService;
use App\Support\ApiResponder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DamageReportController extends Controller
{
    use ApiResponder;

    public function __construct(
        private readonly DamageReportService $damageService,
        // TASK 10 (Security Audit) — see updateStatus().
        private readonly ReportAuthorizationService $reportAuthorizationService,
        // Report Archive — see updateStatus().
        private readonly ReportArchiveService $reportArchiveService,
        private readonly ActivityLogService $activityLogService
    ) {
    }

    public function index(Request $request)
    {
        $authUser = (array)$request->session()->get('auth_user', $request->session()->get('user', []));
        $reports = $this->damageService->listReports($request->all(), $authUser);

        $statusCounts = [
            'pending' => 0,
            'under_review' => 0,
            'repairing' => 0,
            'repaired' => 0,
            'replaced' => 0,
            'closed' => 0,
        ];

        foreach ($reports->items() as $report) {
            $key = (string)$report->status;
            if (array_key_exists($key, $statusCounts)) {
                $statusCounts[$key]++;
            }
        }

        return $this->ok('Damage reports retrieved', [
            'reports' => $reports,
            'status_counts_page' => $statusCounts,
        ]);
    }

    public function store(Request $request)
    {
        $authUser = (array)$request->session()->get('auth_user', $request->session()->get('user', []));

        $validated = $request->validate([
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'department_id' => ['required', 'integer', 'exists:departments,department_id'],
            'source_dispatch_id' => ['nullable', 'integer', 'exists:dispatches,id'],
            'damage_description' => ['required', 'string', 'min:5'],
            'severity_level' => ['required', 'string', 'in:low,medium,high,critical'],
            'repair_notes' => ['nullable', 'string'],
            'damage_image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'override_duplicate' => ['nullable', 'boolean'],
        ]);

        try {
            $report = $this->damageService->createReport(
                $validated,
                $authUser,
                $request->file('damage_image')
            );

            return $this->ok('Damage report created successfully', [
                'damage_report_id' => $report->id,
                'damage_report_code' => $report->damage_report_code,
            ], 201);
        } catch (DuplicateDamageReportException $e) {
            return $this->fail($e->getMessage(), 409, ['duplicate' => $e->getDuplicate()]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->fail('Failed to create damage report: ' . $e->getMessage(), 500);
        }
    }

    public function checkDuplicate(Request $request)
    {
        $validated = $request->validate([
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'department_id' => ['required', 'integer', 'exists:departments,department_id'],
            'damage_description' => ['nullable', 'string'],
        ]);

        $duplicate = $this->damageService->checkDuplicate($validated);

        return $this->ok('Duplicate check complete', [
            'duplicate' => $duplicate,
            'has_duplicate' => $duplicate !== null,
        ]);
    }

    public function show(Request $request, DamageReport $damageReport)
    {
        // TASK 45 (Damage Report Role Redesign) — the detail view presents this
        // damage case as asset-specific information attached to a PRIMARY
        // maintenance report, so it needs that report (plus its assignee and
        // department) to render the "Maintenance Report" and "Assignment"
        // sections and the "View Maintenance Report" link.
        //
        // Read-only and additive: this only widens what an already-authorized
        // caller is shown for a record they can already retrieve. It adds no
        // endpoint, grants no new access, and does not touch the assignment
        // workflow — assignment continues to happen solely on the maintenance
        // report side. `report` stays null for legacy pre-Sprint-4 rows, so the
        // page must treat every field below it as optional.
        $damageReport->load([
            'item:id,name,brand,model,quantity,reserved_quantity',
            'room:id,name,building_id',
            'room.building:id,name',
            'department:department_id,name',
            'reporter:user_id,full_name,email',
            'replacementItem:id,name,brand,model',
            'report:report_id,title,description,status,priority,assigned_to,department_id,created_by,location,created_at',
            'report.assignee:user_id,full_name,department_id',
            'report.assignee.department:department_id,name',
            'report.creator:user_id,full_name',
            'report.department:department_id,name',
        ]);

        return $this->ok('Damage report retrieved', [
            'report' => $damageReport,
        ]);
    }

    public function updateStatus(Request $request, DamageReport $damageReport)
    {
        $authUser = (array)$request->session()->get('auth_user', $request->session()->get('user', []));

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:pending,under_review,repairing,repaired,replaced,closed'],
            'repair_notes' => ['nullable', 'string'],
            'replacement_item_id' => ['nullable', 'integer', 'exists:items,id'],
            'replacement_quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        // TASK 10 (Security Audit) — a damage-report status change propagates
        // onto the linked maintenance report's status (and completed_date) via
        // MaintenanceReportSyncService, so reaching this endpoint without the
        // TASK 9 authorization check was a way to modify a report — including
        // one owned by another department, or one not assigned to the caller —
        // while bypassing ReportController::update() entirely. Authorized
        // against the LINKED maintenance report using the same single source of
        // truth; a legacy damage report with no linked report is unaffected.
        if (!$this->reportAuthorizationService->canModifyLinkedReport($authUser, $damageReport->report_id)) {
            return $this->fail('You are not authorized to modify this report', 403);
        }

        // 2026-09-27 — same rule as ReportController::update(): the
        // Administrator approves and monitors, Head Maintenance runs the
        // repair. The Administrator may only close (cancel) an asset report;
        // moving it through the repair statuses would also move the linked
        // maintenance report (e.g. under_review -> "assigned").
        if (\App\Services\RoleNormalizerService::normalize((string) ($authUser['role'] ?? '')) === 'super_admin'
            && $validated['status'] !== 'closed') {
            return $this->fail('The Administrator can only close an asset report. Repair status updates are handled by Head Maintenance.', 403);
        }

        // Report Archive — this status change is synced onto the linked
        // maintenance report, so it must respect that report's view-only
        // (archived) state exactly like ReportController::update() does.
        $linkedReport = $damageReport->report_id ? MaintenanceReport::query()->find($damageReport->report_id) : null;
        if ($linkedReport && $this->reportArchiveService->isLocked($linkedReport)) {
            return $this->fail(ReportArchiveService::LOCKED_MESSAGE, 423);
        }

        try {
            $updated = $this->damageService->updateStatus($damageReport, $validated, $authUser);
            $updated->load([
                'item:id,name,brand,model',
                'room:id,name',
                'department:department_id,name',
                'replacementItem:id,name,brand,model',
            ]);

            $this->activityLogService->log([
                'action' => 'UPDATE_DAMAGE_REPORT_STATUS',
                'module' => 'damage_reports',
                'entity_type' => 'damage_report',
                'entity_id' => $damageReport->id,
                'details' => 'Updated damage report ' . $damageReport->damage_report_code
                    . ' status to ' . $validated['status'] . '.',
            ], $request);

            return $this->ok('Damage report updated successfully', [
                'report' => $updated,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->fail('Failed to update damage report: ' . $e->getMessage(), 500);
        }
    }

    public function history(Request $request, DamageReport $damageReport)
    {
        $histories = $this->damageService->getHistories($damageReport);

        return $this->ok('Damage report history retrieved', [
            'histories' => $histories,
        ]);
    }
}
