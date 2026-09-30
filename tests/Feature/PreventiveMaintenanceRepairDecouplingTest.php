<?php

namespace Tests\Feature;

use App\Services\PersonnelDirectoryService;
use App\Services\RepairService;
use ReflectionClass;
use ReflectionNamedType;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * TASK 11 — Preventive Maintenance must not depend on the Repair API.
 *
 * preventive-maintenance.php filled BOTH of its personnel selectors — the
 * "Assignee" field on the create/edit form and "Performed by" on the complete
 * modal — from GET /api/repairs/support/technicians. Nothing about "which
 * active maintenance user can be assigned work" is Repair-domain logic; that
 * query has lived in the neutral PersonnelDirectoryService since Task 65.
 *
 * This is the same failure mode Task 42 recorded as a Hard Stop for retiring
 * Repair Request and Task 65 fixed for Dispatch, except no test ever named it
 * for Preventive Maintenance. Retiring the Repair module would have silently
 * emptied two PM dropdowns, and every existing PM test would still have
 * passed, because the coupling is a string in an inline <script>.
 *
 * The suite therefore mixes two assertion styles on purpose:
 *
 *   - SOURCE assertions pin the frontend, where the dependency actually lived.
 *     A server response assertion cannot execute client-side rendering, and
 *     these also run with no database, so they stay meaningful when MySQL is
 *     unavailable.
 *   - HTTP + REFLECTION assertions pin the replacement, proving the new
 *     endpoint really is backed by the shared neutral service and not by a
 *     second copy of the personnel query.
 *
 * SCOPE: the Repair module still exists during Task 11. These tests must not
 * assert that Repair is gone — only that Preventive Maintenance no longer
 * reaches into it.
 */
class PreventiveMaintenanceRepairDecouplingTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    private const PAGE = __DIR__ . '/../../public/frontend/pages/preventive-maintenance.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('pm_repair_decoupling_testing');
        $this->createTestSchema();

        $this->forceLocalTestUrl();
    }

    private function pageSource(): string
    {
        $this->assertFileExists(self::PAGE, 'Preventive Maintenance must not be retired.');

        return (string) file_get_contents(self::PAGE);
    }

    /**
     * Strip HTML and JavaScript comments from page source.
     *
     * Mirrors DamageReportRoleRedesignTest::stripComments() and the Task 9
     * suite. The page now carries a comment block that NAMES the old Repair
     * endpoint in order to explain why it was replaced, so a naive substring
     * search would "find" the removed dependency inside its own changelog.
     * Assertions about what the page actually EXECUTES must look at real code
     * only.
     */
    private function stripComments(string $source): string
    {
        $source = preg_replace('/<!--.*?-->/s', '', $source);
        $source = preg_replace('#/\*.*?\*/#s', '', $source);
        $source = preg_replace('#^[ \t]*//.*$#m', '', $source);

        return $source;
    }

    // ---------------------------------------------------------------
    // The dependency being removed
    // ---------------------------------------------------------------

    /**
     * THE REGRESSION GUARD. This fails the moment anyone reintroduces the
     * Repair endpoint into this page.
     */
    public function test_preventive_maintenance_does_not_call_the_repair_technicians_endpoint(): void
    {
        $executed = $this->stripComments($this->pageSource());

        $this->assertStringNotContainsString(
            '/api/repairs/support/technicians',
            $executed,
            'Preventive Maintenance must not fill its personnel selectors from '
            . 'a Repair Request endpoint. Personnel lookup is neutral and lives '
            . 'in PersonnelDirectoryService.'
        );
    }

    /**
     * Broader than the test above: no Repair API of ANY shape. A future edit
     * could reach for /api/repairs/... for some other support list and
     * recreate the coupling under a different path.
     */
    public function test_preventive_maintenance_does_not_call_any_repair_api(): void
    {
        $executed = $this->stripComments($this->pageSource());

        $this->assertSame(
            0,
            preg_match_all('#/api/repairs\b#', $executed),
            'Preventive Maintenance must have no live reference to the Repair API.'
        );
    }

    // ---------------------------------------------------------------
    // The replacement
    // ---------------------------------------------------------------

    public function test_both_personnel_selectors_use_the_neutral_preventive_maintenance_endpoint(): void
    {
        $executed = $this->stripComments($this->pageSource());

        $this->assertStringContainsString(
            "window.SFMS_PUBLIC_URL('/api/preventive-maintenance/support/personnel')",
            $executed,
            'The replacement must be the module-owned neutral personnel route.'
        );

        // ONE endpoint const feeding all personnel selects — assignee, performed-by,
        // and (since the Annual Schedule / Monthly Checklist rebuild) the shared
        // filter bar's "Assigned Personnel" search. If a later edit gives one
        // select its own endpoint, this catches it.
        $this->assertSame(
            3,
            preg_match_all('#endpoint:\s*personnelEndpoint,#', $executed),
            'The assignee, performed-by, and filter-bar selectors must all read '
            . 'from the single neutral personnel endpoint.'
        );
    }

    /**
     * The Task 75 / Task 76 bug class must survive the repoint: the personnel
     * rows still carry department_id and still have no `id`, so the onSelect
     * override that stores user_id is still required.
     */
    public function test_the_user_id_onselect_override_survived_the_repoint(): void
    {
        $executed = $this->stripComments($this->pageSource());

        $this->assertStringContainsString(
            "document.getElementById('pm-form-assignee-id').value = it.user_id || '';",
            $executed
        );
        $this->assertStringContainsString(
            "document.getElementById('pm-complete-performed-id').value = it.user_id || '';",
            $executed
        );
    }

    // ---------------------------------------------------------------
    // The replacement is backed by the shared service, not a copy
    // ---------------------------------------------------------------

    public function test_the_controller_depends_on_the_neutral_directory_not_the_repair_service(): void
    {
        $constructor = (new ReflectionClass(\App\Http\Controllers\Api\PreventiveMaintenanceController::class))
            ->getConstructor();

        $this->assertNotNull($constructor);

        $dependencies = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                $dependencies[] = $type->getName();
            }
        }

        $this->assertNotContains(
            RepairService::class,
            $dependencies,
            'Preventive Maintenance must not depend on the legacy Repair service.'
        );
        $this->assertContains(
            PersonnelDirectoryService::class,
            $dependencies,
            'Personnel must come from the shared PersonnelDirectoryService so '
            . 'the query is not duplicated.'
        );
    }

    // ---------------------------------------------------------------
    // Behaviour of the replacement endpoint
    // ---------------------------------------------------------------

    public function test_personnel_endpoint_returns_the_same_shape_the_selector_parses(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin', 'full_name' => 'Head Alice']);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->getJson('/api/preventive-maintenance/support/personnel');

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        $this->assertIsArray($response->json('data.users'));

        // SearchableSelect reads displayKey 'full_name' and the onSelect
        // override reads user_id. Both must be present.
        $first = $response->json('data.users.0');
        $this->assertArrayHasKey('full_name', $first);
        $this->assertArrayHasKey('user_id', $first);
    }

    /**
     * "Do not broaden the list simply to make the endpoint work." The audience
     * must stay exactly what the Repair endpoint returned: ACTIVE users whose
     * role is maintenance_admin or maintenance_staff.
     */
    public function test_personnel_endpoint_does_not_broaden_the_audience(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin', 'full_name' => 'Head Alice']);
        $this->seedUser(['role' => 'maintenance_staff', 'full_name' => 'Staff Bob']);
        $this->seedUser(['role' => 'super_admin', 'full_name' => 'Admin Carol']);
        $this->seedUser(['role' => 'user', 'full_name' => 'Requester Dan']);
        $this->seedUser(['role' => 'maintenance_staff', 'full_name' => 'Inactive Erin', 'status' => 'inactive']);

        $names = collect(
            $this->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
                ->getJson('/api/preventive-maintenance/support/personnel')
                ->json('data.users')
        )->pluck('full_name')->all();

        $this->assertContains('Head Alice', $names);
        $this->assertContains('Staff Bob', $names);

        $this->assertNotContains('Admin Carol', $names, 'super_admin is not an assignable technician audience.');
        $this->assertNotContains('Requester Dan', $names, 'Plain requesters must not appear.');
        $this->assertNotContains('Inactive Erin', $names, 'Inactive users must not appear.');
    }

    public function test_personnel_endpoint_supports_the_search_term_the_selector_sends(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin', 'full_name' => 'Head Alice']);
        $this->seedUser(['role' => 'maintenance_staff', 'full_name' => 'Staff Bob']);

        $names = collect(
            $this->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
                ->getJson('/api/preventive-maintenance/support/personnel?q=bob&per_page=50')
                ->json('data.users')
        )->pluck('full_name')->all();

        $this->assertSame(['Staff Bob'], $names);
    }

    /**
     * Maintenance Staff complete their own PM tasks, and the complete modal
     * carries a personnel selector — so staff MUST be able to read this
     * endpoint. Dispatch's support/release-personnel is gated to
     * maintenance_admin/super_admin, which is precisely why it could not be
     * reused here.
     */
    public function test_maintenance_staff_can_read_the_personnel_endpoint(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'full_name' => 'Staff Bob']);

        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->getJson('/api/preventive-maintenance/support/personnel')
            ->assertStatus(200);
    }

    public function test_unauthenticated_request_cannot_read_the_personnel_endpoint(): void
    {
        $this->getJson('/api/preventive-maintenance/support/personnel')
            ->assertStatus(401);
    }

    /**
     * TASK 13 — this test was INVERTED, not deleted.
     *
     * Under Task 11 it asserted a SCOPE BOUNDARY: that repointing Preventive
     * Maintenance did not begin the Repair retirement, so
     * GET /api/repairs/support/technicians was still registered and still
     * returned 200. Task 13 IS the retirement, so that endpoint is gone and
     * the old assertion would now fail.
     *
     * The assertion is flipped rather than removed so the retirement stays
     * pinned. Together with the tests above (which prove PM's own neutral
     * endpoint still works for the same user), this is the required
     * regression proof that Preventive Maintenance survives the retirement.
     *
     * WHY THIS ASSERTS THE ROUTE TABLE AND *NOT* A 404 RESPONSE
     * --------------------------------------------------------
     * The obvious inversion — assert 404 — does not hold in this app, and
     * writing it that way produced a real failure (received 500, expected
     * 404) during Task 13. The cause is PRE-EXISTING and unrelated to this
     * task: the custom renderer in bootstrap/app.php intercepts EVERY
     * Throwable on an `api/*` request and maps anything that is not a
     * ValidationException or ModelNotFoundException to a 500. Symfony's
     * NotFoundHttpException — what an unregistered route raises — therefore
     * surfaces as 500. This is the same already-reported app-wide defect as
     * "missing records return 500 instead of 404"; fixing it would change
     * error semantics across every API endpoint and is out of scope here.
     *
     * So asserting 404 would fail for a reason that has nothing to do with
     * the retirement, and asserting 500 would be worse — it would bake a
     * known defect in as expected behaviour, so the day the handler is fixed
     * this test would break for the wrong reason.
     *
     * Checking the route collection is both immune to the handler and a
     * STRONGER statement: it proves the route is genuinely unregistered,
     * rather than merely inaccessible to this caller. The old HTTP-level
     * assertion could not distinguish "route deleted" from "middleware
     * rejected me"; this one can. The HTTP probe is kept below purely to
     * prove the endpoint no longer SERVES anything, which is the
     * user-visible half of the guarantee.
     */
    public function test_the_repair_technicians_endpoint_is_retired(): void
    {
        $repairRoutes = [];
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/repairs')) {
                $repairRoutes[] = $route->methods()[0] . ' /' . $route->uri();
            }
        }

        $this->assertSame(
            [],
            $repairRoutes,
            "Task 13 removed the /api/repairs endpoint group; these are still registered:\n"
            . implode("\n", $repairRoutes)
        );

        // The endpoint must not still serve data to a privileged caller.
        // Deliberately asserting "not 200" rather than a specific status —
        // see the docblock for why the status code itself is unreliable here.
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);

        $response = $this->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->getJson('/api/repairs/support/technicians');

        $this->assertNotSame(
            200,
            $response->getStatusCode(),
            'GET /api/repairs/support/technicians must no longer return a personnel payload.'
        );
    }

    // ---------------------------------------------------------------
    // Schema
    // ---------------------------------------------------------------

    /**
     * Only `users` is needed: every endpoint exercised here is a personnel
     * lookup, and PersonnelDirectoryService touches no other table.
     */
    private function createTestSchema(): void
    {
        $this->createUsersTable();
    }
}
