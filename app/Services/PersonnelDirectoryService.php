<?php

namespace App\Services;

use App\Models\User;

/**
 * TASK 65 — neutral personnel lookup, extracted from RepairService.
 *
 * This query is "find active users holding one of an allowed set of roles,
 * optionally scoped to a department". It touches only the `users` table and
 * contains no Damage Report or Repair Request domain logic whatsoever, yet it
 * lived on RepairService — which meant the Dispatch release-personnel selector
 * had to depend on the legacy Repair service to fill a dropdown.
 *
 * Task 42 recorded that coupling as a Hard Stop for retiring Repair Request;
 * Task 64 §11 specified re-homing it as independent preparation work that is
 * worthwhile regardless of whether the migration is ever authorized.
 *
 * The query body below was moved VERBATIM from RepairService::searchTechnicians()
 * so every caller kept byte-identical behaviour and JSON shape.
 *
 * TASK 13 PHASE 4 (Repair retirement) — THIS SERVICE MUST REMAIN. It is
 * explicitly protected by the retirement brief, and the re-homing described
 * above is exactly why the Repair backend could be deleted safely: by the time
 * Task 13 ran, not one live consumer reached this query through Repair code.
 *
 * The CURRENT consumers are:
 *
 *   - GET /api/dispatches/support/release-personnel
 *     (DispatchController::releasePersonnel)
 *   - GET /api/preventive-maintenance/support/personnel
 *     (PreventiveMaintenanceController::supportPersonnel) — added by TASK 11,
 *     which moved Preventive Maintenance off the Repair endpoint and onto its
 *     own module's support route, using the DEFAULT arguments so the audience
 *     and JSON shape stayed identical to what that page received before.
 *
 * Two lines that used to appear here have been removed because they no longer
 * exist: the third consumer GET /api/repairs/support/technicians (route and
 * RepairController both deleted in Task 13), and the note that
 * RepairService::searchTechnicians() was kept as a thin delegating wrapper
 * (RepairService is deleted in full). Nothing about the query itself changed.
 */
class PersonnelDirectoryService
{
    /**
     * Search active users eligible to be assigned work.
     *
     * $departmentId === null means "do not filter by department". Restricting
     * to users whose department is genuinely NULL is a different question, so
     * it gets its own flag instead of being overloaded onto null.
     *
     * The default role set (maintenance_admin + maintenance_staff) is the
     * Repair "technician" audience. Dispatch narrows it to maintenance_staff
     * only, scoped to the acting Head's own department.
     */
    public function searchAssignablePersonnel(
        string $query = '',
        int $limit = 20,
        ?array $canonicalRoles = null,
        ?int $departmentId = null,
        bool $matchNullDepartment = false
    ) {
        $allowedRoles = RoleNormalizerService::rawValuesFor(
            $canonicalRoles ?? ['maintenance_admin', 'maintenance_staff']
        );

        $builder = User::query()
            ->select(['user_id', 'full_name', 'email', 'role', 'department_id'])
            ->where('status', 'active')
            ->whereIn('role', $allowedRoles)
            ->orderBy('full_name');

        if ($departmentId !== null) {
            $builder->where('department_id', $departmentId);
        } elseif ($matchNullDepartment) {
            $builder->whereNull('department_id');
        }

        if ($query !== '') {
            $q = strtolower(trim($query));
            $builder->where(function ($inner) use ($q): void {
                $inner->whereRaw('LOWER(full_name) LIKE ?', ["%{$q}%"])
                    ->orWhereRaw('LOWER(email) LIKE ?', ["%{$q}%"]);
            });
        }

        return $builder->limit(max(1, min(50, $limit)))->get();
    }
}
