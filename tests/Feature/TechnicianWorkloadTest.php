<?php

namespace Tests\Feature;

use App\Services\TechnicianWorkloadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * TASK — Technician Workload on the Administrator and Head Maintenance
 * dashboards.
 *
 * WHAT THE FEATURE IS
 * -------------------
 * A read-only card on the two management dashboards showing, per active
 * maintenance technician: how many maintenance reports are currently assigned
 * to them and still live, how many they have finished, and a bar comparing
 * them against the busiest technician. Its purpose is to stop work being
 * handed repeatedly to someone who already has a queue.
 *
 * WHAT THESE TESTS PIN — AND WHY EACH ONE EXISTS
 * ----------------------------------------------
 * 1. THE DEFINITION OF "CURRENTLY ASSIGNED". This is the part most likely to
 *    be quietly broadened later. The count must exclude finished, closed and
 *    cancelled reports, and must never include reports nobody is assigned to.
 *    The tree already contains two rival "still active" definitions —
 *    `NOT IN ('completed','closed')` in DashboardController::maintenanceStats()
 *    and ReportController's overdue filters, versus
 *    `NOT IN ('completed','closed','cancelled')` in
 *    DashboardController::superAdminStats() and the Head Maintenance
 *    dashboard's own client-side `assignedActive` filter. This feature follows
 *    the second (see TechnicianWorkloadService's docblock for the reasoning);
 *    these tests are what stops it silently sliding onto the first.
 *
 * 2. ZERO-WORKLOAD TECHNICIANS SURVIVE. "Who is free" is half of what the card
 *    answers, so an idle technician must come back as a real 0 row, not be
 *    filtered out. Both pre-existing personnel widgets
 *    (superAdminOverview()'s activeStaff and maintenancePersonnel()) also
 *    limit(5); this endpoint deliberately does not, and that is pinned too.
 *
 * 3. AUTHORIZATION IS THE EXISTING CENTRALIZED ONE. Maintenance Staff must be
 *    refused, and refused by the shared EnsureRole middleware on the route
 *    rather than by a second role check inside the controller that could drift
 *    away from it. A test asserts the middleware is actually on the route, so
 *    deleting it cannot pass by accident.
 *
 * 4. NO N+1. One grouped query for the whole roster, however many technicians
 *    exist — asserted by counting the queries the endpoint actually runs.
 *
 * 5. NOTHING IS HARD-CODED. The brief supplied four example technicians with
 *    example counts. Neither dashboard's markup may contain them.
 *
 * WHY SQLITE IS ENOUGH HERE
 * -------------------------
 * Unlike DashboardController::stats(), this endpoint uses no MySQL-only
 * function — a LEFT JOIN, COUNT(CASE WHEN ...) and GROUP BY are portable — so
 * it runs on the standard in-memory harness and needs no scratch MySQL
 * database.
 */
class TechnicianWorkloadTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    private const ENDPOINT = '/api/dashboard/technician-workload';

    private const ADMIN_DASHBOARD = 'public/frontend/pages/dashboard.php';
    private const HEAD_DASHBOARD  = 'public/frontend/pages/maintenance-dashboard.php';
    private const STAFF_DASHBOARD = 'public/frontend/pages/staff-dashboard.php';

    protected function setUp(): void
    {
        parent::setUp();

        // Guards against the $_SESSION leakage documented in
        // UserManagementAuthorizationTest — a leftover super_admin from an
        // earlier test in the same process would otherwise mask the role this
        // file establishes.
        $_SESSION = [];

        $this->useInMemoryDatabase('technician_workload_testing');
        $this->forceLocalTestUrl();
        $this->createTestSchema();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];

        parent::tearDown();
    }

    private function createTestSchema(): void
    {
        Schema::dropIfExists('maintenance_reports');
        Schema::dropIfExists('users');
        Schema::dropIfExists('departments');

        $this->createDepartmentsTable();
        $this->createUsersTable();
        $this->createMaintenanceReportsTable();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────

    private function seedTechnician(string $name, ?int $departmentId = null, string $role = 'maintenance_staff', string $status = 'active'): int
    {
        return $this->seedUser([
            'full_name'     => $name,
            'role'          => $role,
            'status'        => $status,
            'department_id' => $departmentId,
        ]);
    }

    /**
     * $assignedTo === null models an UNASSIGNED report — the case the brief
     * explicitly says must never be counted against anybody.
     */
    private function seedReport(?int $assignedTo, string $status, ?int $departmentId = null): int
    {
        return DB::table('maintenance_reports')->insertGetId([
            'title'         => 'Report ' . uniqid('', true),
            'description'   => 'Seeded for workload coverage.',
            'status'        => $status,
            'priority'      => 'medium',
            'created_by'    => 1,
            'assigned_to'   => $assignedTo,
            'department_id' => $departmentId,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'report_id');
    }

    /** @return array<string, array<string, int>> keyed by technician name */
    private function workloadByName(array $technicians): array
    {
        $byName = [];
        foreach ($technicians as $technician) {
            $byName[$technician['full_name']] = [
                'active'    => (int) $technician['active_count'],
                'completed' => (int) $technician['completed_count'],
                'percent'   => (int) $technician['workload_percent'],
            ];
        }

        return $byName;
    }

    private function pageMarkup(string $relativePath): string
    {
        $path = base_path($relativePath);
        $this->assertFileExists($path, $relativePath . ' is expected to exist.');

        return (string) file_get_contents($path);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1. The definition of "currently assigned"
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The core contract. One technician is given one report in each of the six
     * canonical statuses; only the three live ones may count.
     *
     * If someone later relaxes the exclusion list to the tree's other
     * definition (`completed`/`closed` only), the cancelled report starts
     * counting as current work and this fails with active = 4.
     */
    public function test_active_count_covers_only_live_assigned_reports(): void
    {
        $adminId = $this->seedTechnician('Administrator', null, 'super_admin');
        $tech    = $this->seedTechnician('Euclide Bonifacio');

        foreach (['submitted', 'assigned', 'in_progress', 'completed', 'closed', 'cancelled'] as $status) {
            $this->seedReport($tech, $status);
        }

        $response = $this->actingAsSessionUser($adminId, 'super_admin')->getJson(self::ENDPOINT);
        $response->assertOk();

        $byName = $this->workloadByName($response->json('technicians'));

        $this->assertSame(
            3,
            $byName['Euclide Bonifacio']['active'],
            'Only submitted/assigned/in_progress are current work. completed, closed and cancelled are finished or abandoned '
            . 'and must not be counted as workload.'
        );
    }

    /**
     * An unassigned report belongs to nobody. It must not inflate any
     * technician's number, and must not create a phantom row.
     */
    public function test_unassigned_reports_are_counted_against_nobody(): void
    {
        $adminId = $this->seedTechnician('Administrator', null, 'super_admin');
        $tech    = $this->seedTechnician('Maria Galising');

        $this->seedReport($tech, 'in_progress');
        $this->seedReport(null, 'submitted');
        $this->seedReport(null, 'assigned');

        $response = $this->actingAsSessionUser($adminId, 'super_admin')->getJson(self::ENDPOINT);
        $byName   = $this->workloadByName($response->json('technicians'));

        $this->assertSame(1, $byName['Maria Galising']['active']);
        $this->assertCount(1, $response->json('technicians'), 'Unassigned reports must not produce a technician row.');
    }

    /**
     * "Completed" reuses the pair the rest of the system already treats as
     * finished work — ReportService stamps completed_date and logs
     * COMPLETE_REPORT for `completed` and `closed` alike. A cancelled report is
     * not a completed one.
     */
    public function test_completed_count_covers_completed_and_closed_but_not_cancelled(): void
    {
        $adminId = $this->seedTechnician('Administrator', null, 'super_admin');
        $tech    = $this->seedTechnician('Kristine Manzo');

        $this->seedReport($tech, 'completed');
        $this->seedReport($tech, 'closed');
        $this->seedReport($tech, 'cancelled');
        $this->seedReport($tech, 'in_progress');

        $response = $this->actingAsSessionUser($adminId, 'super_admin')->getJson(self::ENDPOINT);
        $byName   = $this->workloadByName($response->json('technicians'));

        $this->assertSame(2, $byName['Kristine Manzo']['completed']);
        $this->assertSame(1, $byName['Kristine Manzo']['active']);
    }

    /**
     * The service's status lists are the single place the definition lives, so
     * they are asserted directly rather than only through their effects. A
     * change here is a deliberate change to the feature's meaning.
     */
    public function test_the_status_definition_lives_in_one_named_place(): void
    {
        $this->assertSame(
            ['completed', 'closed', 'cancelled'],
            TechnicianWorkloadService::INACTIVE_STATUSES,
            'Workload excludes finished, closed and cancelled reports. See the service docblock for why this follows '
            . 'superAdminStats()/the Head dashboard rather than the overdue-KPI definition.'
        );

        $this->assertSame(['completed', 'closed'], TechnicianWorkloadService::COMPLETED_STATUSES);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. Zero-workload technicians, and no limit
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The brief is explicit: do not hide zero-workload technicians. A
     * technician with nothing assigned, and a technician whose only reports
     * are finished, must both still appear — as real zeroes.
     */
    public function test_technicians_with_no_current_work_are_still_listed(): void
    {
        $adminId = $this->seedTechnician('Administrator', null, 'super_admin');
        $busy    = $this->seedTechnician('Busy Tech');
        $this->seedTechnician('Test Sample');
        $finished = $this->seedTechnician('Finished Tech');

        $this->seedReport($busy, 'assigned');
        $this->seedReport($finished, 'completed');

        $response = $this->actingAsSessionUser($adminId, 'super_admin')->getJson(self::ENDPOINT);
        $byName   = $this->workloadByName($response->json('technicians'));

        $this->assertArrayHasKey('Test Sample', $byName, 'A technician with no assignments at all must not be dropped.');
        $this->assertSame(0, $byName['Test Sample']['active']);
        $this->assertSame(0, $byName['Test Sample']['completed']);
        $this->assertSame(0, $byName['Test Sample']['percent'], 'An idle technician gets an empty bar, not a missing one.');

        $this->assertArrayHasKey('Finished Tech', $byName);
        $this->assertSame(0, $byName['Finished Tech']['active']);
        $this->assertSame(1, $byName['Finished Tech']['completed']);
    }

    /**
     * The two pre-existing personnel widgets both limit(5). This endpoint must
     * not — a roster silently truncated at five would answer "who is free"
     * wrongly the moment a seventh technician exists.
     */
    public function test_the_roster_is_not_truncated(): void
    {
        $adminId = $this->seedTechnician('Administrator', null, 'super_admin');

        for ($i = 1; $i <= 9; $i++) {
            $this->seedTechnician('Technician ' . $i);
        }

        $response = $this->actingAsSessionUser($adminId, 'super_admin')->getJson(self::ENDPOINT);

        $this->assertCount(9, $response->json('technicians'), 'Every active technician must be listed, not the busiest five.');
    }

    /**
     * Inactive (deactivated) accounts are not current personnel and must not
     * appear — matching how every other personnel query in the system filters.
     */
    public function test_deactivated_accounts_are_not_listed(): void
    {
        $adminId = $this->seedTechnician('Administrator', null, 'super_admin');
        $this->seedTechnician('Active Tech');
        $this->seedTechnician('Retired Tech', null, 'maintenance_staff', 'inactive');

        $response = $this->actingAsSessionUser($adminId, 'super_admin')->getJson(self::ENDPOINT);
        $byName   = $this->workloadByName($response->json('technicians'));

        $this->assertArrayHasKey('Active Tech', $byName);
        $this->assertArrayNotHasKey('Retired Tech', $byName);
    }

    /**
     * Technicians stored under a legacy alias are real maintenance personnel.
     * Resolving the role list through RoleNormalizerService (rather than
     * comparing role = 'maintenance_staff' literally, as the two older
     * personnel widgets do) is what keeps them visible — and the same
     * normalization is what makes the Head's own legacy alias
     * ('admin_maintenance') resolve too.
     */
    public function test_legacy_role_aliases_are_recognised_as_personnel(): void
    {
        $adminId = $this->seedTechnician('Administrator', null, 'super_admin');
        $this->seedTechnician('Canonical Tech', null, 'maintenance_staff');
        $this->seedTechnician('Aliased Tech', null, 'eelab_staff');
        $this->seedTechnician('Legacy Tech', null, 'maintenance_personnel');
        $this->seedTechnician('Aliased Head', null, 'admin_maintenance');

        $response = $this->actingAsSessionUser($adminId, 'super_admin')->getJson(self::ENDPOINT);
        $byName   = $this->workloadByName($response->json('technicians'));

        $this->assertArrayHasKey('Canonical Tech', $byName);
        $this->assertArrayHasKey('Aliased Tech', $byName);
        $this->assertArrayHasKey('Legacy Tech', $byName);
        $this->assertArrayHasKey(
            'Aliased Head',
            $byName,
            "A Head stored under the 'admin_maintenance' alias is the same person as one stored as "
            . "'maintenance_admin' and must be listed identically."
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2b. The Head belongs in the list
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The card's roster is maintenance_admin + maintenance_staff.
     *
     * This is NOT a cosmetic addition. maintenance-report-detail.php builds the
     * Administrator's assignment picker as "Assign To (Maintenance Team)" from
     * `u.role IN ('maintenance_admin')` AND `u.role IN ('maintenance_staff')`,
     * so a report's assigned_to can already point at a Head. While the Head was
     * filtered out of this query, every such report was work the system had
     * recorded but the workload card refused to show.
     */
    public function test_the_head_is_listed_alongside_maintenance_staff(): void
    {
        $adminId = $this->seedTechnician('Administrator', null, 'super_admin');
        $head    = $this->seedTechnician('Head Of Maintenance', 1, 'maintenance_admin');
        $staff   = $this->seedTechnician('Staff Technician', 1, 'maintenance_staff');

        $this->seedReport($head, 'in_progress');
        $this->seedReport($staff, 'assigned');

        $response = $this->actingAsSessionUser($adminId, 'super_admin')->getJson(self::ENDPOINT);
        $byName   = $this->workloadByName($response->json('technicians'));

        $this->assertArrayHasKey('Head Of Maintenance', $byName, 'The Head must appear in the same list as the staff.');
        $this->assertArrayHasKey('Staff Technician', $byName);
        $this->assertSame(1, $byName['Head Of Maintenance']['active']);
    }

    /**
     * The Head's numbers come from the SAME query and the same status rules as
     * everyone else's — there is no separate Head branch to drift. Given an
     * identical spread of reports, a Head and a technician must produce
     * identical counts.
     */
    public function test_the_head_workload_uses_the_same_rules_as_staff(): void
    {
        $adminId = $this->seedTechnician('Administrator', null, 'super_admin');
        $head    = $this->seedTechnician('Head Of Maintenance', 1, 'maintenance_admin');
        $staff   = $this->seedTechnician('Staff Technician', 1, 'maintenance_staff');

        foreach ([$head, $staff] as $person) {
            foreach (['submitted', 'assigned', 'in_progress', 'completed', 'closed', 'cancelled'] as $status) {
                $this->seedReport($person, $status);
            }
        }

        $response = $this->actingAsSessionUser($adminId, 'super_admin')->getJson(self::ENDPOINT);
        $byName   = $this->workloadByName($response->json('technicians'));

        $this->assertSame(
            $byName['Staff Technician'],
            $byName['Head Of Maintenance'],
            'Identical work must produce identical numbers — the Head is counted by the same rules, not a special case.'
        );
        $this->assertSame(3, $byName['Head Of Maintenance']['active']);
        $this->assertSame(2, $byName['Head Of Maintenance']['completed']);
    }

    /**
     * A Head with nothing assigned is a real 0 row, exactly like an idle
     * technician — not an omission, and not a fabricated number.
     */
    public function test_a_head_with_no_assigned_work_is_a_real_zero(): void
    {
        $adminId = $this->seedTechnician('Administrator', null, 'super_admin');
        $this->seedTechnician('Idle Head', 1, 'maintenance_admin');
        $busy = $this->seedTechnician('Busy Technician', 1, 'maintenance_staff');

        $this->seedReport($busy, 'assigned');

        $response = $this->actingAsSessionUser($adminId, 'super_admin')->getJson(self::ENDPOINT);
        $byName   = $this->workloadByName($response->json('technicians'));

        $this->assertArrayHasKey('Idle Head', $byName);
        $this->assertSame(0, $byName['Idle Head']['active']);
        $this->assertSame(0, $byName['Idle Head']['completed']);
        $this->assertSame(0, $byName['Idle Head']['percent'], 'An idle Head gets an empty bar, not a missing row.');
    }

    /**
     * super_admin stays out. No assignment surface in the system offers the
     * Administrator as an assignee, so listing them could only ever produce a
     * permanent zero row that implies a capacity the workflow does not have.
     */
    public function test_the_administrator_is_never_listed_as_personnel(): void
    {
        $adminId = $this->seedTechnician('Administrator', null, 'super_admin');
        $this->seedTechnician('Head Of Maintenance', 1, 'maintenance_admin');
        $this->seedTechnician('Staff Technician', 1, 'maintenance_staff');

        $response = $this->actingAsSessionUser($adminId, 'super_admin')->getJson(self::ENDPOINT);
        $names    = array_column($response->json('technicians'), 'full_name');

        $this->assertNotContains('Administrator', $names);
        $this->assertSame(['Head Of Maintenance', 'Staff Technician'], $names);
    }

    /**
     * Sorting is by workload alone — a busier technician outranks an idle
     * Head. The list answers "who has the most work", not "who outranks whom".
     */
    public function test_sorting_is_by_workload_not_by_rank(): void
    {
        $adminId = $this->seedTechnician('Administrator', null, 'super_admin');
        $this->seedTechnician('Idle Head', 1, 'maintenance_admin');
        $busy = $this->seedTechnician('Busy Technician', 1, 'maintenance_staff');

        $this->seedReport($busy, 'assigned');
        $this->seedReport($busy, 'in_progress');

        $response = $this->actingAsSessionUser($adminId, 'super_admin')->getJson(self::ENDPOINT);
        $names    = array_column($response->json('technicians'), 'full_name');

        $this->assertSame(['Busy Technician', 'Idle Head'], $names);
    }

    /**
     * Because the Head now shares the list with technicians, every row has to
     * say which it is. The row carries the normalized role, the label the rest
     * of the app already uses for it, and the department — all stored values.
     */
    public function test_each_row_carries_the_designation_information(): void
    {
        DB::table('departments')->insert([
            'department_id' => 1,
            'name'          => 'Computer',
            'status'        => 'active',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $adminId = $this->seedTechnician('Administrator', null, 'super_admin');
        $this->seedUser([
            'full_name'     => 'Head Of Maintenance',
            'role'          => 'maintenance_admin',
            'status'        => 'active',
            'department_id' => 1,
            'designation'   => 'Head Computer',
        ]);
        $this->seedUser([
            'full_name'     => 'Staff Technician',
            'role'          => 'maintenance_staff',
            'status'        => 'active',
            'department_id' => 1,
            'designation'   => 'Technician',
        ]);

        $rows = collect($this->actingAsSessionUser($adminId, 'super_admin')->getJson(self::ENDPOINT)->json('technicians'))
            ->keyBy('full_name');

        $this->assertSame('maintenance_admin', $rows['Head Of Maintenance']['role']);
        $this->assertSame('Head', $rows['Head Of Maintenance']['role_label']);
        $this->assertSame('Head Computer', $rows['Head Of Maintenance']['designation']);
        $this->assertSame('Computer', $rows['Head Of Maintenance']['department_name']);

        $this->assertSame('maintenance_staff', $rows['Staff Technician']['role']);
        $this->assertSame('Staff', $rows['Staff Technician']['role_label']);
    }

    /**
     * The two labels are the vocabulary User Management already shows for
     * these roles (users.php::getRoleLabel()). Pinned so the card cannot start
     * calling a Head something the rest of the app does not.
     */
    public function test_role_labels_follow_the_existing_vocabulary(): void
    {
        $this->assertSame('Head', TechnicianWorkloadService::roleLabel('maintenance_admin'));
        $this->assertSame('Staff', TechnicianWorkloadService::roleLabel('maintenance_staff'));

        // Aliases resolve before labelling, so a legacy row is not left blank.
        $this->assertSame('Head', TechnicianWorkloadService::roleLabel('admin_maintenance'));
        $this->assertSame('Staff', TechnicianWorkloadService::roleLabel('eelab_staff'));
    }

    /**
     * Missing optional data comes back as null, not as an empty string — the
     * widget uses that to omit the value rather than draw a dangling
     * separator for something the database never recorded.
     */
    public function test_absent_optional_fields_come_back_as_null(): void
    {
        $adminId = $this->seedTechnician('Administrator', null, 'super_admin');
        $this->seedTechnician('Bare Technician', null, 'maintenance_staff');

        $row = $this->actingAsSessionUser($adminId, 'super_admin')
            ->getJson(self::ENDPOINT)
            ->json('technicians.0');

        $this->assertNull($row['designation']);
        $this->assertNull($row['department_name']);
        $this->assertNull($row['avatar']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. The workload bar
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The bar is relative to the busiest technician — the brief's worked
     * example: with a peak of 5, the counts 5/4/3/2/1/0 become
     * 100/80/60/40/20/0 per cent.
     *
     * Asserted through the pure static helper so the rule is pinned
     * independently of any database.
     */
    public function test_bars_are_relative_to_the_busiest_technician(): void
    {
        $rows = TechnicianWorkloadService::withRelativeBars([
            ['full_name' => 'A', 'active_count' => 5],
            ['full_name' => 'B', 'active_count' => 4],
            ['full_name' => 'C', 'active_count' => 3],
            ['full_name' => 'D', 'active_count' => 2],
            ['full_name' => 'E', 'active_count' => 1],
            ['full_name' => 'F', 'active_count' => 0],
        ]);

        $this->assertSame(
            [100, 80, 60, 40, 20, 0],
            array_column($rows, 'workload_percent')
        );
    }

    /**
     * With nobody carrying any work the peak is 0. Everyone must be 0 per cent
     * — not 100 (0/0), which would paint an idle team as maxed out.
     */
    public function test_an_idle_team_produces_empty_bars_not_full_ones(): void
    {
        $rows = TechnicianWorkloadService::withRelativeBars([
            ['full_name' => 'A', 'active_count' => 0],
            ['full_name' => 'B', 'active_count' => 0],
        ]);

        $this->assertSame([0, 0], array_column($rows, 'workload_percent'));
    }

    /**
     * The bar is a comparison, not a capacity gauge. This system defines no
     * workload ceiling anywhere, so the payload must not claim one — no
     * "overloaded" flag, no threshold, invented here.
     */
    public function test_no_overload_threshold_is_invented(): void
    {
        $adminId = $this->seedTechnician('Administrator', null, 'super_admin');
        $tech    = $this->seedTechnician('Very Busy Tech');

        for ($i = 0; $i < 25; $i++) {
            $this->seedReport($tech, 'in_progress');
        }

        $response = $this->actingAsSessionUser($adminId, 'super_admin')->getJson(self::ENDPOINT);
        $row      = $response->json('technicians.0');

        $this->assertSame(25, $row['active_count']);
        $this->assertSame(100, $row['workload_percent'], 'The busiest technician is simply the full bar.');
        $this->assertSame(
            [
                'user_id',
                'full_name',
                // Identity/designation fields, added so the Head can be told
                // apart from the technicians now that they share one list.
                'role',
                'role_label',
                'designation',
                'department_name',
                'avatar',
                'active_count',
                'completed_count',
                'workload_percent',
            ],
            array_keys($row),
            'No overloaded/threshold/capacity field may appear — the system has no such rule to report. '
            . 'Nor may any derived metric the system cannot evidence (an average age/"days" figure in particular).'
        );
    }

    /** The busiest technician leads the list, so the card reads top-down. */
    public function test_technicians_are_ordered_by_current_workload(): void
    {
        $adminId = $this->seedTechnician('Administrator', null, 'super_admin');
        $light   = $this->seedTechnician('Light Load');
        $heavy   = $this->seedTechnician('Heavy Load');

        $this->seedReport($light, 'assigned');
        $this->seedReport($heavy, 'assigned');
        $this->seedReport($heavy, 'in_progress');

        $response = $this->actingAsSessionUser($adminId, 'super_admin')->getJson(self::ENDPOINT);
        $names    = array_column($response->json('technicians'), 'full_name');

        $this->assertSame(['Heavy Load', 'Light Load'], $names);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. Authorization — the existing centralized rule, unchanged
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Maintenance Staff must not receive this management view. The refusal
     * comes from EnsureRole on the route, before the controller runs.
     */
    public function test_maintenance_staff_are_refused(): void
    {
        $staffId = $this->seedTechnician('Staff Member');

        $this->actingAsSessionUser($staffId, 'maintenance_staff')
            ->getJson(self::ENDPOINT)
            ->assertForbidden();
    }

    /** A legacy alias of the staff role must not be a way around the gate. */
    public function test_aliased_staff_roles_are_refused_too(): void
    {
        $staffId = $this->seedTechnician('Aliased Staff', null, 'eelab_staff');

        $this->actingAsSessionUser($staffId, 'eelab_staff')
            ->getJson(self::ENDPOINT)
            ->assertForbidden();
    }

    public function test_anonymous_callers_are_refused(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_both_management_roles_are_admitted(): void
    {
        $adminId = $this->seedTechnician('Administrator', null, 'super_admin');
        $headId  = $this->seedTechnician('Head Maintenance', 7, 'maintenance_admin');

        $this->actingAsSessionUser($adminId, 'super_admin')->getJson(self::ENDPOINT)->assertOk();
        $this->actingAsSessionUser($headId, 'maintenance_admin')->getJson(self::ENDPOINT)->assertOk();
    }

    /**
     * The gate must be the SHARED middleware, not a private copy inside the
     * controller. Without this, someone could delete the middleware, add an
     * `if ($role !== ...)` in the handler, and every behavioural test above
     * would still pass while the project gained a second authorization
     * implementation to keep in sync.
     */
    public function test_the_route_uses_the_shared_role_middleware(): void
    {
        $route = collect(app('router')->getRoutes())->first(
            fn ($candidate) => $candidate->uri() === ltrim(self::ENDPOINT, '/')
        );

        $this->assertNotNull($route, 'The technician workload route is expected to exist.');

        $this->assertContains(
            \App\Http\Middleware\EnsureRole::class . ':super_admin,maintenance_admin',
            $route->gatherMiddleware(),
            'Authorization must reuse the existing centralized EnsureRole middleware, restricted to the two management roles.'
        );
    }

    /**
     * Head Maintenance's authority is department-scoped everywhere else in the
     * system (ReportAuthorizationService), and this view follows it. The
     * Administrator sees the whole system.
     */
    public function test_head_maintenance_sees_only_their_own_department(): void
    {
        $adminId = $this->seedTechnician('Administrator', null, 'super_admin');
        $headId  = $this->seedTechnician('Head Maintenance', 1, 'maintenance_admin');

        $this->seedTechnician('Own Department Tech', 1);
        $this->seedTechnician('Other Department Tech', 2);

        $headView = $this->actingAsSessionUser($headId, 'maintenance_admin')->getJson(self::ENDPOINT);
        $headView->assertOk();
        $headNames = array_column($headView->json('technicians'), 'full_name');

        $this->assertContains('Own Department Tech', $headNames);
        $this->assertNotContains('Other Department Tech', $headNames);
        $this->assertContains(
            'Head Maintenance',
            $headNames,
            'The Head is department personnel too, so their own department view includes themselves.'
        );

        $adminView  = $this->actingAsSessionUser($adminId, 'super_admin')->getJson(self::ENDPOINT);
        $adminNames = array_column($adminView->json('technicians'), 'full_name');

        $this->assertContains('Own Department Tech', $adminNames);
        $this->assertContains('Other Department Tech', $adminNames);
    }

    /**
     * A Head Maintenance account with no department must get nothing rather
     * than silently falling through to a system-wide view.
     */
    public function test_head_maintenance_without_a_department_sees_nothing(): void
    {
        $headId = $this->seedTechnician('Departmentless Head', null, 'maintenance_admin');
        $this->seedTechnician('Some Tech', 1);

        $response = $this->actingAsSessionUser($headId, 'maintenance_admin')->getJson(self::ENDPOINT);

        $response->assertOk();
        $this->assertSame([], $response->json('technicians'));
    }

    /**
     * The feature is read-only. It must not be reachable as a write, so no
     * caller can mistake it for an assignment endpoint.
     */
    public function test_the_endpoint_is_read_only(): void
    {
        $methods = collect(app('router')->getRoutes())
            ->filter(fn ($candidate) => $candidate->uri() === ltrim(self::ENDPOINT, '/'))
            ->flatMap(fn ($candidate) => $candidate->methods())
            ->unique()
            ->sort()
            ->values()
            ->all();

        // HEAD is Laravel's automatic companion to GET, not a second route.
        $this->assertSame(
            ['GET', 'HEAD'],
            $methods,
            'The workload route must be readable only. Registering a write verb here would turn a monitoring widget '
            . 'into an assignment capability.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 5. Performance
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The whole roster in one grouped query. A per-technician lookup would
     * scale with headcount on a dashboard that loads on every login.
     */
    public function test_the_whole_roster_costs_one_query(): void
    {
        $adminId = $this->seedTechnician('Administrator', null, 'super_admin');

        for ($i = 1; $i <= 12; $i++) {
            $technician = $this->seedTechnician('Technician ' . $i);
            $this->seedReport($technician, 'assigned');
            $this->seedReport($technician, 'completed');
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->actingAsSessionUser($adminId, 'super_admin')->getJson(self::ENDPOINT)->assertOk();

        $reportQueries = array_values(array_filter(
            $queries,
            fn (string $sql) => str_contains($sql, 'maintenance_reports')
        ));

        $this->assertCount(
            1,
            $reportQueries,
            'Twelve technicians must still cost exactly one aggregate query, not one per technician. Ran: '
            . implode(' | ', $reportQueries)
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 6. Frontend wiring — both dashboards, and only those two
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_administrator_dashboard_hosts_the_card(): void
    {
        $page   = self::ADMIN_DASHBOARD;
        $markup = $this->pageMarkup($page);

        $this->assertStringContainsString('Technician Workload', $markup, "{$page} must carry the card title.");
        $this->assertStringContainsString(
            'Current workload of maintenance personnel',
            $markup,
            "{$page} must carry the card subtitle."
        );
        $this->assertStringContainsString(
            'id="technician-workload-container"',
            $markup,
            "{$page} must provide the container the shared widget renders into."
        );
        $this->assertStringContainsString(
            "TechnicianWorkload.load('technician-workload-container')",
            $markup,
            "{$page} must actually invoke the widget — a container nothing fills is an empty card."
        );
    }

    public function test_the_administrator_dashboard_loads_the_widget_and_stylesheet(): void
    {
        $page   = self::ADMIN_DASHBOARD;
        $markup = $this->pageMarkup($page);

        $this->assertStringContainsString('assets/js/technician-workload.js', $markup, "{$page} must load the shared widget.");
        $this->assertStringContainsString('assets/css/technician-workload.css', $markup, "{$page} must load the card's stylesheet.");

        $this->assertFileExists(base_path('public/frontend/assets/js/technician-workload.js'));
        $this->assertFileExists(base_path('public/frontend/assets/css/technician-workload.css'));
    }

    /**
     * The card now lives on the Administrator dashboard ONLY.
     *
     * Maintenance Staff never received it. The Head dashboard did, until it
     * was removed on request — removing a card from a page means the page
     * stops referencing it entirely, not that the card is merely hidden with
     * CSS, so both pages are held to the same strict assertion.
     *
     * The service, the shared widget, the stylesheet and the API endpoint all
     * deliberately survive that removal; `test_the_administrator_dashboard_*`
     * above pin that they are still wired up where they are still used.
     */
    public function test_only_the_administrator_dashboard_carries_the_card(): void
    {
        foreach ([self::HEAD_DASHBOARD, self::STAFF_DASHBOARD] as $page) {
            $markup = $this->pageMarkup($page);

            $this->assertStringNotContainsString(
                'Technician Workload',
                $markup,
                "{$page} must not display the card title."
            );
            $this->assertStringNotContainsString(
                'technician-workload',
                $markup,
                "{$page} must not reference the widget's container, script or stylesheet."
            );
            $this->assertStringNotContainsString(
                'TechnicianWorkload',
                $markup,
                "{$page} must not invoke the widget's JS entry point."
            );
        }
    }

    /**
     * The Head dashboard's OTHER cards must survive the removal untouched —
     * the request was to remove one card, not to disturb the page.
     */
    public function test_removing_the_card_left_the_other_head_dashboard_cards_intact(): void
    {
        $markup = $this->pageMarkup(self::HEAD_DASHBOARD);

        foreach ([
            'Low Stock Alerts',
            'Pending Dispatch Requests',
            'loadDashboardData()',
            'loadDepartmentActivity()',
            'loadLowStockAlerts()',
            'loadPendingDispatches()',
        ] as $survivor) {
            $this->assertStringContainsString(
                $survivor,
                $markup,
                'Removing the workload card must not have disturbed the rest of the Head dashboard.'
            );
        }
    }

    /**
     * The brief supplied four example technicians with example counts,
     * immediately followed by "DO NOT hard-code these values". Neither page —
     * nor the shared widget — may contain them.
     */
    public function test_no_example_data_was_baked_into_the_markup(): void
    {
        $sources = [
            self::ADMIN_DASHBOARD,
            self::HEAD_DASHBOARD,
            'public/frontend/assets/js/technician-workload.js',
        ];

        foreach ($sources as $source) {
            $markup = $this->pageMarkup($source);

            foreach (['Euclide Bonifacio', 'Maria Galising', 'Kristine Manzo', 'Test Sample'] as $exampleName) {
                $this->assertStringNotContainsString(
                    $exampleName,
                    $markup,
                    "{$source} must render real personnel from the database, never the brief's example names."
                );
            }
        }
    }

    /**
     * The exact empty-state wording the brief specifies, kept in the one place
     * the widget reads it from.
     */
    public function test_the_empty_state_uses_the_specified_wording(): void
    {
        $widget = $this->pageMarkup('public/frontend/assets/js/technician-workload.js');

        $this->assertStringContainsString(
            "'No maintenance personnel available.'",
            $widget,
            'The empty state must read exactly "No maintenance personnel available."'
        );
    }

    /**
     * Now that the Head shares the list with the technicians, the widget must
     * actually render the role — a row showing only a department would leave a
     * reader unable to tell a Head from a technician, which the brief calls
     * out explicitly. The role label leads the designation line.
     */
    public function test_the_widget_renders_the_role_before_the_department(): void
    {
        $widget = $this->pageMarkup('public/frontend/assets/js/technician-workload.js');

        $this->assertStringContainsString('role_label', $widget, 'The designation line must render the role label.');
        $this->assertStringContainsString(
            'department_name || technician.designation',
            $widget,
            'The detail half of the designation line uses stored department/designation data, in that order.'
        );

        $rolePosition   = strpos($widget, 'technician.role_label');
        $detailPosition = strpos($widget, 'technician.department_name');
        $this->assertIsInt($rolePosition);
        $this->assertIsInt($detailPosition);
        $this->assertLessThan(
            $detailPosition,
            $rolePosition,
            'The role label must be assembled first so it reads "Head • Computer", never a bare department name.'
        );
    }

    /**
     * The avatar reuses the stored users.avatar URL the sidebar already
     * renders, with the name's initial as the fallback. No avatar service, no
     * generated image, and nothing invented for a user who has not uploaded
     * one.
     */
    public function test_the_avatar_uses_stored_user_data_with_an_initial_fallback(): void
    {
        $widget = $this->pageMarkup('public/frontend/assets/js/technician-workload.js');

        $this->assertStringContainsString('tw-avatar-img', $widget);
        $this->assertStringContainsString('tw-avatar-initial', $widget);
        $this->assertStringContainsString('.charAt(0).toUpperCase()', $widget);

        foreach (['gravatar', 'ui-avatars', 'dicebear', 'avatars.githubusercontent'] as $external) {
            $this->assertStringNotContainsString(
                $external,
                strtolower($widget),
                'Avatars must come from the system\'s own stored uploads, never a third-party generator.'
            );
        }
    }

    /**
     * The reference layout shows an age figure ("2.6d"). This system stores no
     * reliable average-age aggregate, and the brief is explicit that one must
     * not be invented, so the card must not contain a days/age metric.
     */
    public function test_no_average_days_metric_is_fabricated(): void
    {
        $widget = $this->pageMarkup('public/frontend/assets/js/technician-workload.js');

        foreach (['avg_days', 'average_days', 'avg_age', 'days_open'] as $invented) {
            $this->assertStringNotContainsString($invented, $widget);
        }
    }

    /**
     * The card is presentation only. Nothing in it may offer to assign work —
     * that would be handing these roles a new capability through a monitoring
     * widget, which the brief forbids.
     */
    public function test_the_card_offers_no_assignment_control(): void
    {
        $widget = $this->pageMarkup('public/frontend/assets/js/technician-workload.js');

        foreach (["method: 'POST'", 'method: "POST"', "method: 'PATCH'", "method: 'PUT'"] as $write) {
            $this->assertStringNotContainsString(
                $write,
                $widget,
                'The workload widget is read-only; it must never write.'
            );
        }
    }

    /**
     * The two pre-existing personnel widgets must keep working exactly as they
     * did — this task added a view, it did not replace them.
     */
    public function test_the_existing_personnel_endpoint_still_works(): void
    {
        $headId = $this->seedTechnician('Head Maintenance', 1, 'maintenance_admin');
        $tech   = $this->seedTechnician('Own Department Tech', 1);
        $this->seedReport($tech, 'assigned', 1);

        $response = $this->actingAsSessionUser($headId, 'maintenance_admin')
            ->getJson('/api/dashboard/maintenance/personnel');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertSame('Own Department Tech', $response->json('personnel.0.full_name'));
        $this->assertSame(1, (int) $response->json('personnel.0.assigned_count'));
    }
}
