<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * TASK 65 PHASE 6 — the 12 dangling damage_report notifications.
 *
 * WHAT WAS FOUND IN THE LIVE DATABASE
 * -----------------------------------
 * 12 rows in `notifications` carry entity_type='damage_report' with an
 * entity_id (84, 85, 86, 98, 99, 100 — two recipients each, user_id 1 and 18)
 * for which no damage_reports row exists. Those ids are also absent from
 * maintenance_reports.report_id, so the referenced reports were deleted
 * outright rather than merely re-keyed.
 *
 * They dangle because `notifications` has ZERO foreign key constraints:
 * entity_type/entity_id is a soft polymorphic pointer, so nothing cascaded
 * when the damage reports were removed.
 *
 * WHY THIS TEST EXISTS RATHER THAN A CLEANUP
 * ------------------------------------------
 * The brief requires the orphans to be classified before anything is touched,
 * and forbids altering historical data unless a specific application defect
 * requires it. The classification reached is A + C (harmless historical
 * orphan data that is also a migration concern), NOT B (a live UI/API
 * defect) — and that conclusion rests on two behavioural claims which this
 * test exists to prove rather than assert:
 *
 *   1. The notification READ path never dereferences entity_id at all.
 *      NotificationController::baseQuery() only left-joins maintenance_reports
 *      when a notifications.report_id COLUMN exists; the live table has no
 *      such column, so the query selects literal NULLs and filters solely on
 *      user_id. An orphan is therefore indistinguishable from any other row
 *      to the API — it cannot produce a null-dereference, a missing-join
 *      error, or a dropped row.
 *
 *   2. The deep-link PREFLIGHT degrades gracefully. notification.js
 *      (ENTITY_ROUTES.damage_report) probes GET /api/damage-reports/{id}
 *      before navigating, and treats any non-ok response as
 *      { error: 'failed' }, which handleNotificationClick() surfaces as a
 *      dismissable alert instead of navigating to a detail page that would
 *      render empty. So the missing entity must come back as a structured,
 *      handled JSON failure rather than a fatal — that is exactly what
 *      GATE E ("do not create an unhandled runtime failure") turns on.
 *      It does; the STATUS CODE on that failure is nonetheless wrong (500
 *      instead of 404) for a pre-existing, separately-owned reason explained
 *      in full on the third test below.
 *
 * If either property regressed, the orphans would silently become a genuine
 * runtime defect — and no existing test would notice, because no existing
 * test seeds a notification whose entity is absent.
 *
 * Nothing here modifies live data. The orphan condition is REPRODUCED in the
 * isolated SQLite test database by seeding a notification that points at a
 * damage_reports id which was never inserted.
 */
class DanglingNotificationEntityTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    /**
     * Mirrors the live orphans: entity_type is set, entity_id points nowhere.
     */
    private const MISSING_DAMAGE_REPORT_ID = 84;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('dangling_notification_entity_testing');
        $this->createTestSchema();
        $this->forceLocalTestUrl();
    }

    /**
     * Property 1 — the list endpoint returns the orphan intact.
     *
     * Not merely "does not 500": the row must still be RETURNED, because the
     * user has a real notification about a real past event. Silently dropping
     * it would be its own defect, and would also mask the orphan condition
     * from anyone auditing the data later.
     */
    public function test_notification_list_returns_a_dangling_entity_reference_intact(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);
        $notificationId = $this->seedDanglingNotification($userId);

        $this->assertSame(
            0,
            DB::table('damage_reports')->where('id', self::MISSING_DAMAGE_REPORT_ID)->count(),
            'Precondition: the referenced damage report must not exist.'
        );

        $response = $this->actingAsSessionUser($userId, 'maintenance_staff')
            ->getJson('/api/notifications');

        $response->assertOk();

        $row = collect($response->json('data.notifications'))
            ->firstWhere('notification_id', $notificationId);

        $this->assertNotNull(
            $row,
            'A notification whose entity was deleted must still be listed — the '
            . 'event it describes did happen.'
        );
        $this->assertSame('damage_report', $row['entity_type']);
        $this->assertSame(self::MISSING_DAMAGE_REPORT_ID, (int) $row['entity_id']);
    }

    /**
     * The unread counter must not be skewed by orphans either — it is rendered
     * as a badge on every page, so an exception or a miscount here would be
     * highly visible.
     */
    public function test_unread_endpoint_counts_a_dangling_notification_normally(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);
        $this->seedDanglingNotification($userId, ['is_read' => 0]);

        $response = $this->actingAsSessionUser($userId, 'maintenance_staff')
            ->getJson('/api/notifications/unread');

        $response->assertOk();
        $this->assertSame(1, $response->json('data.count'));
    }

    /**
     * Property 2 — the preflight the frontend actually performs.
     *
     * This is the single request that stands between a dangling notification
     * and a broken page, so it is the request GATE E turns on: the missing
     * entity must produce a STRUCTURED, HANDLED response rather than an
     * unhandled fatal, so that notification.js can decline to navigate.
     *
     * WHY THIS ASSERTS 500 AND DOES NOT FIX IT
     * ----------------------------------------
     * The status code returned is 500 rather than the 404 that
     * bootstrap/app.php visibly intends. That is NOT caused by the dangling
     * notifications and is not specific to them: Laravel core's
     * Handler::render() runs prepareException() — which rewrites
     * ModelNotFoundException into NotFoundHttpException — BEFORE the app's
     * custom render callback, so that callback's `instanceof
     * ModelNotFoundException` branch is unreachable dead code and every
     * implicit route-model-bound api/* route in the application falls through
     * to its generic 500 arm.
     *
     * This was already found, root-caused, and CONFIRMED WITH THE USER by
     * Task 73 as a shared app-wide bug in bootstrap/app.php lying outside that
     * task's module, and a dedicated follow-up task was spun off to fix it.
     * DamageReportControllerTest::
     * test_show_of_a_missing_report_currently_returns_500_not_404_known_app_wide_issue()
     * pins the same behaviour for the same reason. Task 65 is a
     * migration-safety preparation task; silently absorbing another task's
     * open defect would both exceed this task's boundary and require editing
     * that existing test to match. So this test documents reality and defers
     * the fix, exactly as Task 73 did.
     *
     * What matters for GATE E is proven regardless of the status code, and is
     * what the two assertions below actually check: the response is the
     * application's own JSON envelope (not a fatal, not an HTML error page),
     * and it is non-ok — which is the exact condition notification.js branches
     * on. The frontend's guard is `if (!response.ok)`, which is satisfied by
     * 404 and 500 alike, so the orphaned rows degrade to a dismissable alert
     * today and would continue to once the status code is corrected.
     */
    public function test_deep_link_preflight_for_a_missing_damage_report_degrades_without_a_fatal(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);
        $this->seedDanglingNotification($userId);

        $response = $this->actingAsSessionUser($userId, 'maintenance_staff')
            ->getJson('/api/damage-reports/' . self::MISSING_DAMAGE_REPORT_ID);

        // The contract notification.js relies on: any non-ok response makes
        // resolveNotificationTarget() return { error: 'failed' }, which
        // handleNotificationClick() turns into an alert instead of navigating.
        $this->assertGreaterThanOrEqual(
            400,
            $response->getStatusCode(),
            'The preflight must report failure so the deep link is not followed '
            . 'into an empty detail page.'
        );

        $this->assertFalse(
            $response->json('success'),
            'The failure must arrive as the application JSON envelope, not an HTML '
            . 'error page or a fatal — notification.js parses this response as JSON.'
        );

        // 2026-09-27 — the bootstrap/app.php renderer fix landed (updated
        // together with DamageReportControllerTest's equivalent, as planned):
        // a missing record is now a clean 404, not a 500.
        $this->assertSame(
            404,
            $response->getStatusCode(),
            'A missing damage report must answer 404 now that the renderer keeps HTTP status codes.'
        );
    }

    /**
     * The orphan must remain deletable by its owner. This is what makes the
     * recommended future cleanup possible WITHOUT a data migration: a user can
     * already dismiss these rows themselves through the existing UI.
     */
    public function test_owner_can_still_delete_a_dangling_notification(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);
        $notificationId = $this->seedDanglingNotification($userId);

        $this->actingAsSessionUser($userId, 'maintenance_staff')
            ->deleteJson("/api/notifications/{$notificationId}")
            ->assertOk();

        $this->assertSame(
            0,
            DB::table('notifications')->where('id', $notificationId)->count()
        );
    }

    private function seedDanglingNotification(int $userId, array $overrides = []): int
    {
        return DB::table('notifications')->insertGetId(array_merge([
            'user_id' => $userId,
            // Verbatim title of all 12 live rows.
            'title' => 'New Damage Report Submitted',
            'message' => 'A new damage report has been submitted.',
            'entity_type' => 'damage_report',
            'entity_id' => self::MISSING_DAMAGE_REPORT_ID,
            'is_read' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('damage_reports');
        Schema::dropIfExists('maintenance_reports');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('items');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createDepartmentsTable();
        $this->createItemsTable();
        $this->createMaintenanceReportsTable();
        $this->createDamageReportsTable();
        $this->createNotificationsTable();

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Deliberately WITHOUT a report_id column and WITHOUT foreign keys, which
     * is what the live table looks like — both facts are load-bearing for the
     * behaviour under test.
     */
    private function createNotificationsTable(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id');
            $table->string('title');
            $table->text('message');
            $table->string('entity_type', 40)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
    }

    private function createDamageReportsTable(): void
    {
        Schema::create('damage_reports', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('damage_report_code')->nullable();
            $table->unsignedInteger('report_id')->nullable();
            $table->unsignedInteger('item_id')->nullable();
            $table->unsignedInteger('room_id')->nullable();
            $table->unsignedInteger('department_id')->nullable();
            $table->unsignedBigInteger('source_dispatch_id')->nullable();
            $table->text('damage_description')->nullable();
            $table->string('severity_level')->default('medium');
            $table->unsignedInteger('reported_by')->nullable();
            $table->string('status')->default('pending');
            $table->string('image_path')->nullable();
            $table->text('repair_notes')->nullable();
            $table->unsignedInteger('replacement_item_id')->nullable();
            $table->integer('replacement_quantity')->nullable();
            $table->unsignedInteger('replacement_transaction_id')->nullable();
            $table->unsignedInteger('replaced_by')->nullable();
            $table->timestamp('replaced_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }
}
