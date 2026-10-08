<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * TASK 33 PHASE 7/9 — Unified Create Report Capability Completion (RETIRED).
 *
 * This suite used to pin that the unified Create Report page's asset
 * sub-form ("Report Against a Specific Asset") could send item_id+room_id
 * plus severity_level, damage_image and repair_notes to POST /api/reports,
 * and that ReportController::store()'s asset-linked fork
 * (storeWithAssetDetails() / later ReportService::createAssetReport()) ->
 * DamageReportService::createReport() accepted and persisted all of it.
 *
 * 2026-10-08 — that fork was removed from ReportController::store(), by
 * explicit user decision: Damage Reports must come from exactly ONE trigger
 * — a Need Change request — not from naming an asset on Create Report. The
 * "Report Against a Specific Asset" sub-form (and every field unique to it:
 * item_id, room_id, deployed_asset_id, severity_level, damage_image,
 * repair_notes, override_duplicate) was removed from create-report.php at
 * the same time. None of those fields are validated or read by
 * ReportController::store() any more — POST /api/reports now always takes
 * the general-report path, and any of these keys sent in the body are
 * silently ignored.
 *
 * Nothing this suite exercised was deleted outright:
 * DamageReportService::createReport(), severity_level/damage_image/
 * repair_notes validation, and image storage all still exist and are still
 * covered by DamageReportControllerTest against the standalone
 * POST /api/damage-reports endpoint. Only this endpoint's reachability into
 * that logic is gone, which is what the tests below now pin.
 *
 * The two tests that were never about the asset fork at all — the plain
 * general-report path and the Need Change path — are unchanged; they
 * already expected zero damage_reports rows and remain accurate.
 */
class ReportCreateAssetSeverityImageTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('report_create_asset_severity_image_testing');
        $this->createTestSchema();

        $this->forceLocalTestUrl();
    }

    // ---------------------------------------------------------------
    // 1. Create Report still works (general, non-asset path unaffected)
    // ---------------------------------------------------------------

    public function test_general_report_creation_without_asset_fields_still_works(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'Furniture',
                'title' => 'Broken Chair',
                'description' => 'Chair leg is broken.',
                'location' => 'Room 101',
                'priority' => 'medium',
                'department_id' => $deptId,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $this->assertSame(1, DB::table('maintenance_reports')->count());
        // The general path never touches damage_reports at all.
        $this->assertSame(0, DB::table('damage_reports')->count());
    }

    // ---------------------------------------------------------------
    // 2. Asset fields are now inert — severity_level
    // ---------------------------------------------------------------

    // 2026-10-08 — renamed and inverted (was
    // test_asset_linked_report_accepts_a_valid_severity_level). item_id/
    // room_id/severity_level no longer route this through the removed
    // asset fork; they are unvalidated, unread extra fields, and the
    // request is created as an ordinary general report with no
    // damage_reports row.
    public function test_severity_level_on_report_creation_is_now_ignored(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId]);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'HVAC / Aircon',
                'title' => 'Aircon not cooling',
                'description' => 'Unit runs but no cold air.',
                'location' => 'Room 204',
                'priority' => 'high',
                'department_id' => $deptId,
                'item_id' => $itemId,
                'room_id' => $roomId,
                'severity_level' => 'critical',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        // No damage_report_id in the payload any more — there is no damage
        // report to reference.
        $this->assertArrayNotHasKey('damage_report_id', $response->json('data'));

        $this->assertSame(0, DB::table('damage_reports')->count());
        $this->assertSame(1, DB::table('maintenance_reports')->count());
        $reportRow = DB::table('maintenance_reports')->first();
        $this->assertSame('Aircon not cooling', $reportRow->title);
        $this->assertSame('general', $reportRow->report_category);
    }

    // ---------------------------------------------------------------
    // 3. Asset fields are now inert — an "invalid" severity is no longer
    //    validated at all, since severity_level is no longer a validated
    //    field on this endpoint.
    // ---------------------------------------------------------------

    // 2026-10-08 — renamed and inverted (was
    // test_asset_linked_report_rejects_an_invalid_severity_level). A bogus
    // severity_level value used to 422 because the asset fork validated it
    // with 'in:low,medium,high,critical'. That validation rule is gone from
    // ReportController::store(), so the exact same payload now succeeds —
    // the field is simply never looked at.
    public function test_severity_level_is_no_longer_validated_at_all(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId]);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'HVAC / Aircon',
                'title' => 'Aircon not cooling',
                'description' => 'Unit runs but no cold air.',
                'location' => 'Room 204',
                'priority' => 'high',
                'department_id' => $deptId,
                'item_id' => $itemId,
                'room_id' => $roomId,
                'severity_level' => 'catastrophic', // not one of low/medium/high/critical
            ]);

        $response->assertStatus(201);
        $this->assertSame(0, DB::table('damage_reports')->count());
        $this->assertSame(1, DB::table('maintenance_reports')->count());
    }

    // ---------------------------------------------------------------
    // 4. Asset fields are now inert — damage_image
    // ---------------------------------------------------------------

    // 2026-10-08 — renamed and inverted (was
    // test_asset_linked_report_accepts_a_valid_image_and_persists_its_path).
    // damage_image is no longer a validated field on ReportController::
    // store(); the file is never read or stored, and the request is created
    // as an ordinary general report.
    public function test_damage_image_on_report_creation_is_now_ignored(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId]);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->post('/api/reports', [
                'problem_type' => 'HVAC / Aircon',
                'title' => 'Aircon not cooling',
                'description' => 'Unit runs but no cold air.',
                'location' => 'Room 204',
                'priority' => 'high',
                'department_id' => $deptId,
                'item_id' => $itemId,
                'room_id' => $roomId,
                'severity_level' => 'high',
                'damage_image' => $this->makeRealJpegUploadedFile(),
            ]);

        $response->assertStatus(201);
        $this->assertSame(0, DB::table('damage_reports')->count());
        $this->assertSame(1, DB::table('maintenance_reports')->count());
    }

    // 2026-10-08 — renamed and inverted (was
    // test_asset_linked_report_rejects_a_disallowed_file_extension_for_the_image).
    // A disallowed extension used to 422 because the asset fork validated
    // damage_image with 'mimes:jpg,jpeg,png,webp,gif'. That rule is gone
    // from ReportController::store(), so the exact same payload now
    // succeeds — the field is simply never looked at.
    public function test_damage_image_extension_is_no_longer_validated_at_all(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->post('/api/reports', [
                'problem_type' => 'HVAC / Aircon',
                'title' => 'Aircon not cooling',
                'description' => 'Unit runs but no cold air.',
                'location' => 'Room 204',
                'priority' => 'high',
                'department_id' => $deptId,
                'item_id' => $itemId,
                'room_id' => $roomId,
                'severity_level' => 'high',
                'damage_image' => \Illuminate\Http\UploadedFile::fake()->create('malware.exe', 100),
            ])
            ->assertStatus(201);

        $this->assertSame(0, DB::table('damage_reports')->count());
    }

    // ---------------------------------------------------------------
    // TASK 33 PHASE 9 — Repair Notes. Asset fields are now inert.
    // ---------------------------------------------------------------

    // 2026-10-08 — renamed and inverted (was
    // test_asset_linked_report_accepts_repair_notes_and_persists_it).
    // repair_notes is no longer a validated field on ReportController::
    // store() and there is no damage_reports row for it to be persisted to.
    public function test_repair_notes_on_report_creation_is_now_ignored(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId]);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'HVAC / Aircon',
                'title' => 'Aircon not cooling',
                'description' => 'Unit runs but no cold air.',
                'location' => 'Room 204',
                'priority' => 'high',
                'department_id' => $deptId,
                'item_id' => $itemId,
                'room_id' => $roomId,
                'severity_level' => 'high',
                'repair_notes' => 'Compressor may need replacing; checked filter first.',
            ]);

        $response->assertStatus(201);
        $this->assertSame(0, DB::table('damage_reports')->count());
        $this->assertSame(1, DB::table('maintenance_reports')->count());
    }

    // 2026-10-08 — the paired "without repair_notes" case from before this
    // change is no longer meaningful on its own (there is no damage_reports
    // row either way now), so it was folded into the single test above
    // rather than kept as a separate no-op duplicate.

    // ---------------------------------------------------------------
    // 5. Existing report categories are unaffected — need_change path
    //    (a different optional sub-form on the same page) still ignores
    //    severity_level/damage_image entirely.
    // ---------------------------------------------------------------

    // 2026-10-08 — corrected to match current behavior. This test's
    // assertions were written when ReportController::store()'s general
    // branch did not persist need_change_item_id at all (only update() did).
    // A separate, already-approved change made earlier in this same session
    // ("a report created with a Need Change request must also be visible on
    // the Damage Reports page from the moment it's created") made
    // ReportService::createGeneralReport() persist need_change_item_id and
    // call DamageReportService::attachNeedChangeAsDamageReport() right at
    // creation time — see createGeneralReport()'s own 2026-10-08 comment.
    // That is unrelated to, and unaffected by, the asset-picker removal this
    // suite is otherwise about; it is fixed here because this test's old
    // assertions no longer matched reality.
    public function test_need_change_report_creation_is_unaffected_by_the_new_fields(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $stockItemId = $this->seedItem(['item_type' => 'inventory_stock', 'quantity' => 5]);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'HVAC / Aircon',
                'title' => 'Need replacement filter',
                'description' => 'Filter needs replacing.',
                'location' => 'Room 305',
                'priority' => 'medium',
                'department_id' => $deptId,
                'need_change_item_id' => $stockItemId,
            ]);

        $response->assertStatus(201);
        $this->assertSame(1, DB::table('maintenance_reports')->count());

        // need_change_item_id is persisted at creation, and the auto-link
        // creates exactly one damage_reports row for it — this is the ONE
        // trigger into damage_reports that still exists after the
        // asset-picker removal.
        $reportRow = DB::table('maintenance_reports')->first();
        $this->assertSame($stockItemId, (int) $reportRow->need_change_item_id);
        $this->assertSame(1, DB::table('damage_reports')->count());
    }

    // ---------------------------------------------------------------
    // 6. Existing RBAC remains unchanged — POST /api/reports is still
    //    gated by the exact same EnsureRole middleware regardless of what
    //    fields are in the body (it never was specific to the asset fork;
    //    see routes/web.php's EnsureRole::class . ':maintenance_admin,
    //    maintenance_staff' on this route).
    // ---------------------------------------------------------------

    // 2026-10-08 — renamed (was test_super_admin_cannot_create_an_asset_linked_report).
    // The "asset-linked" framing was never accurate for what this pins:
    // super_admin is blocked from POSTing to /api/reports at all, asset
    // fields or not.
    public function test_super_admin_cannot_create_a_report(): void
    {
        $deptId = $this->seedDepartment();
        $adminId = $this->seedUser(['role' => 'super_admin', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId]);

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson('/api/reports', [
                'problem_type' => 'HVAC / Aircon',
                'title' => 'Aircon not cooling',
                'description' => 'Unit runs but no cold air.',
                'location' => 'Room 204',
                'priority' => 'high',
                'department_id' => $deptId,
                'item_id' => $itemId,
                'room_id' => $roomId,
                'severity_level' => 'high',
            ])
            ->assertStatus(403);

        $this->assertSame(0, DB::table('damage_reports')->count());
        $this->assertSame(0, DB::table('maintenance_reports')->count());
    }

    // 2026-10-08 — renamed and inverted (was
    // test_maintenance_admin_can_create_an_asset_linked_report). The RBAC
    // allow-case still holds (maintenance_admin may still POST
    // /api/reports), but the asset fields in the body no longer produce a
    // damage_reports row — see
    // test_severity_level_on_report_creation_is_now_ignored above for why.
    public function test_maintenance_admin_can_create_a_report(): void
    {
        $deptId = $this->seedDepartment();
        $adminId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId]);

        $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->postJson('/api/reports', [
                'problem_type' => 'HVAC / Aircon',
                'title' => 'Aircon not cooling',
                'description' => 'Unit runs but no cold air.',
                'location' => 'Room 204',
                'priority' => 'high',
                'department_id' => $deptId,
                'item_id' => $itemId,
                'room_id' => $roomId,
                'severity_level' => 'medium',
            ])
            ->assertStatus(201);

        $this->assertSame(0, DB::table('damage_reports')->count());
        $this->assertSame(1, DB::table('maintenance_reports')->count());
    }

    // ---------------------------------------------------------------
    // Schema / seeding — mirrors DamageReportControllerTest's isolated
    // in-memory schema, since this suite exercises the same underlying
    // tables through a different endpoint (/api/reports instead of
    // /api/damage-reports).
    // ---------------------------------------------------------------

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('damage_report_histories');
        Schema::dropIfExists('damage_reports');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('maintenance_reports');
        Schema::dropIfExists('items');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createDepartmentsTable();
        $this->createRoomsTable();
        $this->createItemsTable();
        $this->createMaintenanceReportsTable();
        $this->addMaintenanceReportAssetColumns();
        $this->createInventoryTransactionsTable();
        $this->createActivityLogsTable();
        $this->createNotificationsTable();
        $this->createDamageReportsTable();
        $this->createDamageReportHistoriesTable();

        Schema::enableForeignKeyConstraints();
    }

    private function addMaintenanceReportAssetColumns(): void
    {
        Schema::table('maintenance_reports', function ($table): void {
            $table->string('report_category', 30)->default('general')->after('description');
            $table->unsignedInteger('item_id')->nullable()->after('report_category');
            $table->unsignedBigInteger('source_dispatch_id')->nullable()->after('item_id');
        });
    }

    private function createRoomsTable(): void
    {
        Schema::create('rooms', function ($table): void {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });
    }

    private function createNotificationsTable(): void
    {
        Schema::create('notifications', function ($table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('report_id')->nullable();
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
        Schema::create('damage_reports', function ($table): void {
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

    private function createDamageReportHistoriesTable(): void
    {
        Schema::create('damage_report_histories', function ($table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('damage_report_id');
            $table->string('action_type')->nullable();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('notes')->nullable();
            $table->text('meta_json')->nullable();
            $table->unsignedInteger('changed_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    private function seedDepartment(array $overrides = []): int
    {
        return DB::table('departments')->insertGetId(array_merge([
            'name' => 'Department ' . uniqid(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides), 'department_id');
    }

    private function seedRoom(array $overrides = []): int
    {
        return DB::table('rooms')->insertGetId(array_merge([
            'name' => 'Room ' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * The GD extension is not installed in this environment, so
     * UploadedFile::fake()->image() (which requires imagecreatetruecolor())
     * cannot be used to exercise the store() endpoint's 'image' validation
     * rule, which inspects actual file content rather than the declared
     * extension. A minimal, valid, real 1x1 JPEG is written to a temp file
     * and wrapped as a test UploadedFile instead — copied verbatim from
     * DamageReportControllerTest::makeRealJpegUploadedFile().
     *
     * 2026-10-08 — still used by test_damage_image_on_report_creation_is_now_ignored
     * to prove the upload is harmlessly ignored, not that it is stored.
     */
    private function makeRealJpegUploadedFile(): \Illuminate\Http\UploadedFile
    {
        $bytes = base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
            . 'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIy'
            . 'MjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIA'
            . 'AhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEB'
            . 'AQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX'
            . '/9k='
        );

        $tmpPath = tempnam(sys_get_temp_dir(), 'dmgtest') . '.jpg';
        file_put_contents($tmpPath, $bytes);

        return new \Illuminate\Http\UploadedFile($tmpPath, 'damage.jpg', 'image/jpeg', null, true);
    }
}
