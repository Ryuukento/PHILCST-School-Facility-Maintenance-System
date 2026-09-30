<?php

namespace App\Services;

use App\Models\MaintenanceReport;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * TASK 9 — Role + Department Based Authorization.
 *
 * Single source of truth for "can this user modify this report", used by
 * every report update path (status/assign/edit/priority/need-change/cancel/
 * close) instead of each duplicating its own role+department check.
 *
 * Policy:
 *   - super_admin        : always TRUE (full system access).
 *   - maintenance_admin  : TRUE only when user.department_id == report.department_id.
 *   - maintenance_staff  : TRUE only when user.department_id == report.department_id
 *                           AND the report is assigned_to them.
 *   - anything else      : FALSE.
 *
 * A department_id of null on both sides is treated as a match (legacy/
 * unassigned data on either the user or the report does not block existing
 * workflows that never set a department).
 */
class ReportAuthorizationService
{
    /**
     * TASK 49 — the exact denial text the API has always returned for these
     * two cases, promoted from string literals inside ReportController::update()
     * to constants here, so the rule and the message it produces live together
     * and no call site can drift from the wording the frontend surfaces to the
     * user (maintenance-report-detail.php renders `data.message` verbatim).
     */
    public const NEED_CHANGE_APPROVE_DENIED_MESSAGE = 'Only an Administrator can approve Need Change requests';
    public const NEED_CHANGE_REJECT_DENIED_MESSAGE = 'Only an Administrator can reject Need Change requests';

    public function canModifyReport(array $authUser, MaintenanceReport $report): bool
    {
        $role = RoleNormalizerService::normalizeWithStaffDefault((string) ($authUser['role'] ?? ''));

        if ($role === 'super_admin') {
            return true;
        }

        if (!in_array($role, ['maintenance_admin', 'maintenance_staff'], true)) {
            return false;
        }

        if (!$this->sameDepartment($authUser, $report)) {
            return false;
        }

        if ($role === 'maintenance_staff') {
            $userId = (int) ($authUser['user_id'] ?? 0);

            return $userId > 0 && (int) $report->assigned_to === $userId;
        }

        return true;
    }

    /**
     * TASK 49 — Need Change Authorization Hardening.
     *
     * THE Need Change approval rule, in exactly one place.
     *
     * The rule itself is unchanged and is not restated anywhere else: approving
     * a Need Change request is Administrator-only. Head Maintenance raises the
     * request and Maintenance Staff carries out the work, but releasing stock
     * against it is the Administrator's decision — the same segregation of
     * duties DispatchAuthorizationService::canApproveDispatch() already
     * expresses for Dispatch.
     *
     * This method previously existed only as an inline
     * `if ($role !== 'super_admin')` inside ReportController::update(), which is
     * what TASK 48 recorded as weakness W-2: the entire rule lived at a single
     * unguarded call site, with nothing behind it and no test proving it was
     * there.
     *
     * Role resolution uses normalize() rather than normalizeWithStaffDefault(),
     * for the same reason DispatchAuthorizationService::role() does: an empty or
     * absent role must grant nothing rather than being defaulted into a real
     * role. That substitution is provably behaviour-preserving *for this check*,
     * because the two normalizers differ on exactly one input — the empty string,
     * which becomes '' here and 'maintenance_staff' there — and neither of those
     * two values equals 'super_admin'. Every other input is passed through the
     * identical ALIASES table. No alias maps to 'super_admin', so no aliased role
     * can satisfy this test.
     */
    public function canApproveNeedChange(array $authUser): bool
    {
        return RoleNormalizerService::normalize((string) ($authUser['role'] ?? '')) === 'super_admin';
    }

    /**
     * TASK 49 — rejection carries the same restriction as approval, and has
     * done since the retired legacy implementation. Expressed as a delegating
     * method rather than a second comparison so that the two can never drift
     * apart, while still giving each call site a name that says what it is
     * asking about.
     */
    public function canRejectNeedChange(array $authUser): bool
    {
        return $this->canApproveNeedChange($authUser);
    }

    /**
     * TASK 49 — the enforcing form of canApproveNeedChange(), for use at the
     * point where the approval is actually performed rather than at the point
     * where the request arrives.
     *
     * Throws instead of returning a bool (mirroring
     * DispatchAuthorizationService::assertAssignableReleasePersonnel()) so that
     * a caller cannot proceed by ignoring a return value — the failure mode
     * TASK 48 was concerned about.
     *
     * Returns the authenticated actor id on success. NeedChangeService stamps
     * need_change_approved_by from this return value rather than from a
     * separately-passed integer, which makes it structurally impossible for the
     * identity that was authorized and the identity recorded as the approver to
     * be two different users — precisely the shape of the activity_logs #1220
     * discrepancy that TASK 48 investigated.
     *
     * A session carrying an authorized role but no usable user_id is refused
     * rather than allowed to record an approval attributed to user 0.
     */
    public function assertCanApproveNeedChange(array $authUser): int
    {
        if (!$this->canApproveNeedChange($authUser)) {
            throw new AuthorizationException(self::NEED_CHANGE_APPROVE_DENIED_MESSAGE);
        }

        $userId = (int) ($authUser['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new AuthorizationException(self::NEED_CHANGE_APPROVE_DENIED_MESSAGE);
        }

        return $userId;
    }

    /**
     * TASK 10 — Final Security & Permission Audit.
     *
     * Department-ownership only, without the per-role modification rules of
     * canModifyReport(). Used by ReportController::destroy(), where the
     * pre-existing rule is "super_admin, or the report's own author" and the
     * only thing missing was department ownership — a report that has moved to
     * another department must not stay deletable by its original author.
     *
     * Exposed (rather than re-deriving the comparison at the call site) so
     * department-matching logic continues to live in exactly one place.
     */
    public function sharesDepartment(array $authUser, MaintenanceReport $report): bool
    {
        return $this->sameDepartment($authUser, $report);
    }

    /**
     * TASK 10 — Final Security & Permission Audit.
     *
     * Authorization for the *indirect* write paths that reach
     * maintenance_reports without going through ReportController::update():
     * DamageReportService::updateStatus() and RepairService both propagate a
     * damage/repair status change onto the linked maintenance report via
     * MaintenanceReportSyncService, which would otherwise let a caller change
     * a report's status while sidestepping canModifyReport() entirely.
     *
     * Returns TRUE when there is no linked maintenance report (a legacy
     * damage report with report_id = null has no maintenance report to
     * protect, so the damage-report-only workflow is unaffected), or when the
     * caller may modify the linked report under the normal policy.
     */
    public function canModifyLinkedReport(array $authUser, ?int $reportId): bool
    {
        if (!$reportId) {
            return true;
        }

        $report = MaintenanceReport::query()->find($reportId);
        if (!$report) {
            return true;
        }

        return $this->canModifyReport($authUser, $report);
    }

    /**
     * TASK 13 — Dispatch Release Assignment Workflow.
     *
     * The raw department comparison, lifted out of sameDepartment() so the
     * Dispatch module can enforce the *same* Task 9 rule without this service
     * having to know about Dispatch (it is deliberately typed to
     * MaintenanceReport). sameDepartment() below now delegates here, so there
     * is exactly one implementation of "do these two departments match" in the
     * codebase — including the null === null case, which stays a match for the
     * reasons given in the class doc comment.
     *
     * Behaviour is unchanged for every existing caller; this is a pure
     * extraction.
     */
    public function departmentsMatch(?int $leftDepartmentId, ?int $rightDepartmentId): bool
    {
        return $leftDepartmentId === $rightDepartmentId;
    }

    private function sameDepartment(array $authUser, MaintenanceReport $report): bool
    {
        $userDepartmentId = $authUser['department_id'] ?? null;
        $userDepartmentId = $userDepartmentId !== null ? (int) $userDepartmentId : null;

        $reportDepartmentId = $report->department_id;
        $reportDepartmentId = $reportDepartmentId !== null ? (int) $reportDepartmentId : null;

        return $this->departmentsMatch($userDepartmentId, $reportDepartmentId);
    }
}
