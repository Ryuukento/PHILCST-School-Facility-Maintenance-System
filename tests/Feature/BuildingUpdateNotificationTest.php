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
 * TASK 52 — regression coverage for a duplicate-notification defect found
 * while auditing every notification-producing flow: BuildingController::update()
 * called notifyBuildingUpdated() unconditionally on every successful PATCH,
 * even when the submitted name/description were identical to the existing
 * row (e.g. a form re-submitted with no actual edits). That spammed every
 * active Super Admin/Head Maintenance user with a "Building Updated"
 * notification for a no-op change.
 *
 * Fixed by only calling notifyBuildingUpdated() when the name or description
 * actually changed, mirroring the "only notify on a genuine change" pattern
 * DispatchService::assignReleasePersonnel() already established for
 * release-personnel reassignment.
 */
class BuildingUpdateNotificationTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('building_update_notification_testing');
        $this->createTestSchema();
        $this->forceLocalTestUrl();
    }

    public function test_no_op_update_does_not_notify(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin', 'status' => 'active']);
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'status' => 'active']);
        $buildingId = $this->seedBuilding(['name' => 'Science Wing', 'description' => 'Main science building']);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->patchJson("/api/buildings/{$buildingId}", [
                'name' => 'Science Wing',
                'description' => 'Main science building',
            ])
            ->assertOk();

        $this->assertSame(
            0,
            DB::table('notifications')->where('entity_type', 'building')->where('entity_id', $buildingId)->count(),
            'Re-submitting identical name/description must not notify anyone.'
        );
        // Sanity: $headId exists solely so a real change (tested below) would
        // have someone to notify; assert it's unused here too.
        $this->assertSame(0, DB::table('notifications')->where('user_id', $headId)->count());
    }

    public function test_genuine_name_change_still_notifies_admins(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin', 'status' => 'active']);
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'status' => 'active']);
        $buildingId = $this->seedBuilding(['name' => 'Science Wing', 'description' => 'Main science building']);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->patchJson("/api/buildings/{$buildingId}", [
                'name' => 'Science and Engineering Wing',
                'description' => 'Main science building',
            ])
            ->assertOk();

        $notifications = DB::table('notifications')->where('entity_type', 'building')->where('entity_id', $buildingId);
        $this->assertSame(1, (clone $notifications)->where('user_id', $headId)->count());
        // The acting admin must not self-notify.
        $this->assertSame(0, (clone $notifications)->where('user_id', $adminId)->count());
    }

    public function test_genuine_description_only_change_still_notifies_admins(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin', 'status' => 'active']);
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'status' => 'active']);
        $buildingId = $this->seedBuilding(['name' => 'Science Wing', 'description' => 'Old description']);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->patchJson("/api/buildings/{$buildingId}", [
                'name' => 'Science Wing',
                'description' => 'Updated description',
            ])
            ->assertOk();

        $this->assertSame(
            1,
            DB::table('notifications')->where('entity_type', 'building')->where('entity_id', $buildingId)->where('user_id', $headId)->count()
        );
    }

    private function seedBuilding(array $overrides = []): int
    {
        return DB::table('buildings')->insertGetId(array_merge([
            'name' => 'Main Building ' . uniqid('', true),
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('floors');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('buildings');
        Schema::dropIfExists('users');

        $this->createUsersTable();

        // BuildingController::update() logs to activity_logs via
        // ActivityLogService on every genuine change (TASK 17), which is
        // exactly the path these "genuine change" tests exercise.
        $this->createActivityLogsTable();

        Schema::create('buildings', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('floors', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('building_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('rooms', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('building_id')->nullable();
            $table->unsignedInteger('floor_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });

        $this->createNotificationsTable();

        Schema::enableForeignKeyConstraints();
    }

    private function createNotificationsTable(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
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
}
