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
 * TASK 52 — regression coverage for App\Http\Controllers\Api\NotificationController,
 * which previously had zero dedicated test coverage. NotificationController::markRead()
 * and ::destroy() already scope their UPDATE/DELETE by the session user_id (unlike the
 * legacy public/backend/models/Notification.php IDOR this same audit found and fixed —
 * see the TASK 52 comments on Notification::markAsRead()/delete()). These tests lock
 * that ownership scoping in place so a future edit cannot silently regress it into the
 * same IDOR: any authenticated user must not be able to read, mark-read, or delete
 * another user's notification by guessing/enumerating its id.
 */
class NotificationControllerOwnershipTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('notification_controller_ownership_testing');
        $this->createTestSchema();
        $this->forceLocalTestUrl();
    }

    public function test_user_cannot_mark_another_users_notification_as_read(): void
    {
        $ownerId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);
        $attackerId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);
        $notificationId = $this->seedNotification($ownerId);

        $this->actingAsSessionUser($attackerId, 'maintenance_staff')
            ->postJson("/api/notifications/{$notificationId}/read")
            ->assertOk();

        $this->assertSame(
            0,
            DB::table('notifications')->where('id', $notificationId)->value('is_read'),
            'A user must not be able to mark another user\'s notification as read.'
        );
    }

    public function test_user_cannot_delete_another_users_notification(): void
    {
        $ownerId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);
        $attackerId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);
        $notificationId = $this->seedNotification($ownerId);

        $this->actingAsSessionUser($attackerId, 'maintenance_staff')
            ->deleteJson("/api/notifications/{$notificationId}")
            ->assertStatus(404);

        $this->assertSame(
            1,
            DB::table('notifications')->where('id', $notificationId)->count(),
            'A user must not be able to delete another user\'s notification.'
        );
    }

    public function test_user_cannot_see_another_users_notification_in_index_or_unread(): void
    {
        $ownerId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);
        $viewerId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);
        $this->seedNotification($ownerId);

        $indexResponse = $this->actingAsSessionUser($viewerId, 'maintenance_staff')
            ->getJson('/api/notifications');
        $indexResponse->assertOk();
        $this->assertSame([], $indexResponse->json('data.notifications'));
        $this->assertSame(0, $indexResponse->json('data.unread_count'));

        $unreadResponse = $this->actingAsSessionUser($viewerId, 'maintenance_staff')
            ->getJson('/api/notifications/unread');
        $unreadResponse->assertOk();
        $this->assertSame([], $unreadResponse->json('data.notifications'));
        $this->assertSame(0, $unreadResponse->json('data.count'));
    }

    public function test_owner_can_mark_own_notification_as_read_and_delete_it(): void
    {
        $ownerId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);
        $notificationId = $this->seedNotification($ownerId);

        $this->actingAsSessionUser($ownerId, 'maintenance_staff')
            ->postJson("/api/notifications/{$notificationId}/read")
            ->assertOk();
        $this->assertSame(1, DB::table('notifications')->where('id', $notificationId)->value('is_read'));

        $this->actingAsSessionUser($ownerId, 'maintenance_staff')
            ->deleteJson("/api/notifications/{$notificationId}")
            ->assertOk();
        $this->assertSame(0, DB::table('notifications')->where('id', $notificationId)->count());
    }

    public function test_mark_all_read_does_not_touch_another_users_notifications(): void
    {
        $ownerId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);
        $otherId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'active']);
        $ownNotificationId = $this->seedNotification($ownerId);
        $otherNotificationId = $this->seedNotification($otherId);

        $this->actingAsSessionUser($ownerId, 'maintenance_staff')
            ->postJson('/api/notifications/read-all')
            ->assertOk();

        $this->assertSame(1, DB::table('notifications')->where('id', $ownNotificationId)->value('is_read'));
        $this->assertSame(0, DB::table('notifications')->where('id', $otherNotificationId)->value('is_read'));
    }

    private function seedNotification(int $userId, array $overrides = []): int
    {
        return DB::table('notifications')->insertGetId(array_merge([
            'user_id' => $userId,
            'report_id' => null,
            'title' => 'Test Notification',
            'message' => 'Something happened.',
            'entity_type' => null,
            'entity_id' => null,
            'is_read' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('maintenance_reports');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        // NotificationController::baseQuery() left-joins maintenance_reports
        // whenever notifications.report_id exists, regardless of whether any
        // notification actually references one.
        $this->createMaintenanceReportsTable();
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
