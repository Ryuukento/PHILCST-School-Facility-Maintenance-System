<?php

namespace App\Services;

use App\Models\Dispatch;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * TASK 13 — Dispatch Release Assignment Workflow.
 *
 * Single source of truth for "who may do what to a dispatch" under the
 * revised business process:
 *
 *   Head Maintenance (maintenance_admin)
 *     creates the dispatch AND picks the Release Personnel, may reassign
 *     while the dispatch is not yet released, may NOT approve, may NOT release.
 *
 *   Administrator (super_admin)
 *     approves or rejects Head-created dispatches. May NOT release.
 *
 *     TASK 41 — may ALSO create dispatches, which follow a second workflow:
 *     an Administrator-created dispatch requires NO approval and is created
 *     directly in the existing 'approved' (releasable) state. It is NOT
 *     self-approved — approved_by/approved_at stay NULL so the record never
 *     claims an approval that did not happen. Administrator may still not
 *     REASSIGN personnel after creation (canAssignReleasePersonnel is
 *     unchanged); they choose the personnel once, at creation.
 *
 *   Maintenance Staff (maintenance_staff)
 *     releases — but ONLY the dispatch that names them in
 *     release_assigned_to. Role membership alone is explicitly not enough.
 *
 * Department-based restriction on WHO may be assigned as Release Personnel
 * (TASK 9/41) was REMOVED per product decision: `dispatches.department_id`
 * is a reporting/attribution tag only (feeds AnalyticsReportController's
 * department-usage comparison) — it was never a property of the items in the
 * dispatch, and a single dispatch routinely mixes items from several
 * equipment categories. Restricting the releaser to staff sharing that one
 * tag added a false constraint (release is a stock hand-off, not
 * trade-specific work) and was already inconsistent: Head Maintenance's own
 * department was used for scoping regardless of what they picked in the
 * dropdown, while Administrator's scoping DID follow the dropdown.
 * ReportAuthorizationService::departmentsMatch() remains used elsewhere
 * (e.g. report visibility) and is untouched by this change.
 *
 * TASK 57 — Release Personnel eligibility widened from Maintenance Staff
 * ONLY to Maintenance Staff + Head Maintenance (maintenance_admin). Per
 * product decision, Administrator (super_admin) is deliberately EXCLUDED:
 * an Administrator may create a dispatch, but must not also be eligible as
 * its own release personnel. Every place role membership gates release
 * eligibility — assertAssignableReleasePersonnel(), canReleaseDispatch(),
 * and the releasePersonnel() selector endpoint — was updated together so
 * the UI's candidate list and the server-side release check never disagree
 * about who qualifies.
 *
 * Every method takes the *session* auth user array (the legacy session bridge
 * shape used everywhere else in this app), never a trusted client-supplied id.
 */
class DispatchAuthorizationService
{
    public function __construct(
        private readonly ReportAuthorizationService $reportAuthorizationService
    ) {
    }

    /**
     * TASK 41 — Administrator Create Dispatch Without Approval.
     *
     * Head Maintenance still creates dispatches. Administrator may now ALSO
     * create them, under a different workflow (see creationRequiresApproval()
     * below): an Administrator-created dispatch skips the approval step
     * entirely rather than being self-approved.
     *
     * Maintenance Staff remain excluded — this task did not widen creation to
     * them, and the route middleware names both allowed roles explicitly so a
     * crafted request cannot reach the service with any other role.
     */
    public function canCreateDispatch(array $authUser): bool
    {
        return in_array($this->role($authUser), ['maintenance_admin', 'super_admin'], true);
    }

    /**
     * TASK 41 — THE approval-bypass rule, in exactly one place.
     *
     * Returns whether a dispatch created by this user must go through the
     * Administrator approval step before it becomes releasable.
     *
     *   super_admin        -> false : no approval step exists for their
     *                                 dispatches. They are created directly in
     *                                 'approved' (the existing enum value that
     *                                 means "releasable"), with approved_by and
     *                                 approved_at left NULL so the audit trail
     *                                 never claims an approval happened.
     *   everyone else      -> true  : unchanged Head Maintenance workflow —
     *                                 created 'pending', requires an
     *                                 Administrator to approve.
     *
     * Deliberately expressed as "is the role super_admin" rather than "is the
     * role NOT maintenance_admin", so that any role added to
     * canCreateDispatch() in future defaults to REQUIRING approval instead of
     * silently inheriting the bypass.
     */
    public function creationRequiresApproval(array $authUser): bool
    {
        return $this->role($authUser) !== 'super_admin';
    }

    /**
     * Approve/reject is Administrator-only. Head Maintenance approving its own
     * dispatch would defeat the entire point of the approval step.
     */
    public function canApproveDispatch(array $authUser): bool
    {
        return $this->role($authUser) === 'super_admin';
    }

    /**
     * Reassignment is allowed while the dispatch still has a release ahead of
     * it — i.e. pending OR approved — and is refused once the inventory has
     * actually moved (released) or the dispatch is dead (cancelled).
     *
     * Previously also required the acting Head's own department to match the
     * dispatch's department_id. Removed along with the rest of the
     * department-based release-personnel restriction (see class doc comment)
     * — a Head reassigning the releaser of a dispatch they created should not
     * be blocked by a reporting tag unrelated to who may hand off stock.
     */
    public function canAssignReleasePersonnel(array $authUser, Dispatch $dispatch): bool
    {
        if ($this->role($authUser) !== 'maintenance_admin') {
            return false;
        }

        return in_array($dispatch->status, ['pending', 'approved'], true);
    }

    /**
     * The core security rule of this task: identity, not role — role is only
     * used to keep Administrator out of the pool entirely (see TASK 57).
     *
     * A dispatch may only be released by the exact user recorded in
     * release_assigned_to. An unassigned dispatch is releasable by nobody —
     * that is intentional, and it is what makes every pre-TASK-13 dispatch
     * (which has no assignment) safe by default.
     *
     * TASK 57 — widened from maintenance_staff-only to also accept
     * maintenance_admin (Head Maintenance), matching the widened assignment
     * pool in assertAssignableReleasePersonnel(). super_admin is still
     * refused here even if somehow written into release_assigned_to, so a
     * data anomaly cannot let an Administrator release their own dispatch.
     */
    public function canReleaseDispatch(array $authUser, Dispatch $dispatch): bool
    {
        if (!in_array($this->role($authUser), ['maintenance_admin', 'maintenance_staff'], true)) {
            return false;
        }

        $assignedTo = $dispatch->release_assigned_to !== null ? (int) $dispatch->release_assigned_to : null;
        $userId = (int) ($authUser['user_id'] ?? 0);

        return $assignedTo !== null && $userId > 0 && $assignedTo === $userId;
    }

    /**
     * Maintenance Staff see only what is assigned to them. Everyone else keeps
     * the pre-existing "see everything" behaviour, so no Administrator or Head
     * Maintenance view narrows as a side effect of this task.
     */
    public function shouldScopeIndexToAssignments(array $authUser): bool
    {
        return $this->role($authUser) === 'maintenance_staff';
    }

    /**
     * Validates a proposed Release Personnel selection SERVER-SIDE.
     *
     * Client-side filtering of the searchable selector is a convenience only;
     * this is the check that actually prevents a crafted request from
     * assigning an inactive user or a non-Maintenance-Staff role.
     *
     * Department matching (previously required here — a Head's own
     * department, or an Administrator's chosen destination department) was
     * REMOVED: see the class doc comment. The $authUser parameter is kept
     * (rather than dropped) because every call site already passes it and a
     * future role/identity check may still need it; it is simply unused by
     * the department logic that used to live here.
     *
     * Throws rather than returning a bool so every call site surfaces the same
     * 400-level message through the controllers' existing ValidationException
     * handling instead of inventing its own error text.
     */
    public function assertAssignableReleasePersonnel(
        array $authUser,
        int $releaseAssignedTo
    ): User {
        $user = User::query()->find($releaseAssignedTo);

        if (!$user || $user->status !== 'active') {
            throw ValidationException::withMessages([
                'release_assigned_to' => 'Selected release personnel is invalid or inactive.',
            ]);
        }

        // TASK 57 — widened from Maintenance Staff only to also accept Head
        // Maintenance (maintenance_admin). Administrator (super_admin) is
        // deliberately still refused: they may create the dispatch, but may
        // not also be its release personnel.
        if (!in_array(RoleNormalizerService::normalize((string) ($user->role ?? '')), ['maintenance_admin', 'maintenance_staff'], true)) {
            throw ValidationException::withMessages([
                'release_assigned_to' => 'Release personnel must be a Head Maintenance or Maintenance Staff member.',
            ]);
        }

        return $user;
    }

    private function role(array $authUser): string
    {
        // normalize() rather than normalizeWithStaffDefault(): the latter turns
        // an EMPTY role into 'maintenance_staff', which for this service would
        // mean a session with no role at all could satisfy the staff branch.
        // Here an unknown/empty role must grant nothing, so it is left as ''
        // and matches none of the three checks below.
        return RoleNormalizerService::normalize((string) ($authUser['role'] ?? ''));
    }
}
