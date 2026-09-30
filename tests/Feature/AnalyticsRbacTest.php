<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * TASK 82 GAP #1 — ANALYTICS RBAC.
 *
 * routes/web.php previously mounted the entire `analytics` route group
 * (14 endpoints: options, overview, top-requested, top-repaired,
 * monthly-comparison, department-usage, semester-comparison,
 * inventory-health, inventory-summary, low-stock, damaged-items,
 * dispatch-report, repair-report, replacement-report, semester-detail)
 * with NO EnsureRole middleware at all — only the outer
 * EnsureApiAuthenticated group, so any authenticated session (including a
 * freshly-registered, not-yet-approved account, whose raw role is 'user'
 * until an Administrator assigns a real one) could reach every endpoint.
 *
 * The frontend has always restricted analytics to
 * super_admin/maintenance_admin/maintenance_staff — see the nav-link guard
 * in includes/sidebar.php ("in_array($user['role'], ['super_admin',
 * 'maintenance_admin', 'maintenance_staff'])") and the page-level role
 * normalization at the top of pages/analytics-dashboard.php. The fix
 * (routes/web.php, `analytics` prefix group) adds
 * `EnsureRole::class . ':super_admin,maintenance_admin,maintenance_staff'`
 * to bring route-level enforcement in line with that pre-existing,
 * already-shipped access model — it does not restrict access beyond what
 * the UI already implies, and does not invent a second authorization
 * system (same EnsureRole middleware used everywhere else in this file).
 *
 * These tests exercise two endpoints that only touch the `items` table
 * (low-stock, inventory-health) so they can run against the shared
 * in-memory SQLite test schema without needing the MySQL-specific
 * DATE_FORMAT/DATE_SUB/CURDATE() raw SQL used by some of the other
 * analytics endpoints (overview, semester-comparison, etc.) — that SQL is
 * unrelated to this authorization fix and unaffected by it.
 */
class AnalyticsRbacTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('analytics_rbac_testing');
        $this->createTestSchema();

        $this->forceLocalTestUrl();
    }

    public function test_unauthenticated_request_is_denied(): void
    {
        $this->getJson('/api/analytics/low-stock')
            ->assertStatus(401);
    }

    public function test_unapproved_pending_user_role_is_denied(): void
    {
        // AuthController::register() creates new accounts with the raw role
        // 'user' until an Administrator approves and assigns a real role.
        // This is the concrete "unauthorized role" this system actually has
        // — there is no separate low-privilege "viewer" role to test against.
        $pendingId = $this->seedUser(['role' => 'user']);

        $this
            ->actingAsSessionUser($pendingId, 'user')
            ->getJson('/api/analytics/low-stock')
            ->assertStatus(403);
    }

    public function test_super_admin_can_access_analytics(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->getJson('/api/analytics/low-stock')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_maintenance_admin_can_access_analytics(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin']);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->getJson('/api/analytics/inventory-health')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_maintenance_staff_can_access_analytics(): void
    {
        // Per the frontend's existing access model (sidebar nav guard +
        // analytics-dashboard.php default), maintenance_staff is an
        // intentionally-included role for analytics, not an excluded one.
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->getJson('/api/analytics/low-stock')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_existing_low_stock_analytics_behavior_is_unaffected_by_the_rbac_fix(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);

        $this->seedItem(['name' => 'Low Stock Widget', 'quantity' => 2, 'reorder_level' => 5, 'status' => 'low_stock']);
        $this->seedItem(['name' => 'Healthy Widget', 'quantity' => 50, 'reorder_level' => 5, 'status' => 'available']);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->getJson('/api/analytics/low-stock')
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame('Low Stock Widget', $items[0]['name']);
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('items');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createItemsTable();

        Schema::enableForeignKeyConstraints();
    }
}
