<?php

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * TASK 52 — regression coverage for the IDOR fix in the legacy
 * public/backend/models/Notification.php class, which sits behind
 * public/backend/models/notifications.php (a plain procedural PHP endpoint
 * dispatcher, not routed through Laravel — it is invoked directly by the web
 * server, so it cannot be exercised through Laravel's HTTP test client).
 *
 * Previously, Notification::markAsRead($notificationId) and
 * Notification::delete($notificationId) trusted the client-supplied id alone
 * with no ownership check: any authenticated user could mark another user's
 * notification as read, or delete it, by guessing/enumerating
 * notification_id. Both methods now require a $userId argument and scope
 * their UPDATE/DELETE with "AND user_id = ?". This test instantiates the
 * legacy class directly against a real PDO SQLite connection (mirroring how
 * public/backend/bootstrap.php hands it a real PDO instance) to lock that
 * ownership scoping in place at the source, independent of the HTTP layer.
 */
class LegacyNotificationModelOwnershipTest extends TestCase
{
    private PDO $pdo;
    /** @var object */
    private $notification;

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../../public/backend/models/Notification.php';

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // SHOW COLUMNS FROM (used by ensureSchemaMetadata()) is MySQL-only
        // syntax; under SQLite it throws and is caught, falling back to the
        // documented legacy defaults (primary key 'notification_id',
        // report_id column present) — so the schema here matches those
        // defaults, which is also the real production MySQL schema shape.
        $this->pdo->exec('
            CREATE TABLE notifications (
                notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                report_id INTEGER NULL,
                title TEXT NOT NULL,
                message TEXT NOT NULL,
                is_read INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )
        ');

        $this->notification = new \Notification($this->pdo);
    }

    private function seedNotification(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO notifications (user_id, title, message, is_read, created_at) VALUES (?, ?, ?, 0, datetime(\'now\'))'
        );
        $stmt->execute([$userId, 'Test Notification', 'Something happened.']);

        return (int) $this->pdo->lastInsertId();
    }

    public function test_mark_as_read_does_not_affect_another_users_notification(): void
    {
        $ownerId = 1;
        $attackerId = 2;
        $notificationId = $this->seedNotification($ownerId);

        $this->notification->markAsRead($notificationId, $attackerId);

        $stmt = $this->pdo->prepare('SELECT is_read FROM notifications WHERE notification_id = ?');
        $stmt->execute([$notificationId]);
        $this->assertSame('0', (string) $stmt->fetchColumn(), 'A user must not be able to mark another user\'s notification as read.');
    }

    public function test_mark_as_read_affects_own_notification(): void
    {
        $ownerId = 1;
        $notificationId = $this->seedNotification($ownerId);

        $this->notification->markAsRead($notificationId, $ownerId);

        $stmt = $this->pdo->prepare('SELECT is_read FROM notifications WHERE notification_id = ?');
        $stmt->execute([$notificationId]);
        $this->assertSame('1', (string) $stmt->fetchColumn());
    }

    public function test_delete_does_not_affect_another_users_notification(): void
    {
        $ownerId = 1;
        $attackerId = 2;
        $notificationId = $this->seedNotification($ownerId);

        $this->notification->delete($notificationId, $attackerId);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM notifications WHERE notification_id = ?');
        $stmt->execute([$notificationId]);
        $this->assertSame('1', (string) $stmt->fetchColumn(), 'A user must not be able to delete another user\'s notification.');
    }

    public function test_delete_affects_own_notification(): void
    {
        $ownerId = 1;
        $notificationId = $this->seedNotification($ownerId);

        $this->notification->delete($notificationId, $ownerId);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM notifications WHERE notification_id = ?');
        $stmt->execute([$notificationId]);
        $this->assertSame('0', (string) $stmt->fetchColumn());
    }
}
