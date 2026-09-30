<?php
/**
 * Notification Model
 */

class Notification {
    private $pdo;
    private $schemaLoaded = false;
    private $primaryKey = 'notification_id';
    private $hasReportIdColumn = true;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    private function ensureSchemaMetadata() {
        if ($this->schemaLoaded) {
            return;
        }

        $this->schemaLoaded = true;

        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM notifications");
            $columns = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN, 0) : [];
            $columns = is_array($columns) ? array_map('strtolower', $columns) : [];

            if (in_array('id', $columns, true) && !in_array('notification_id', $columns, true)) {
                $this->primaryKey = 'id';
            } else {
                $this->primaryKey = 'notification_id';
            }

            $this->hasReportIdColumn = in_array('report_id', $columns, true);
        } catch (Throwable $e) {
            // Keep legacy defaults when schema probing fails.
            $this->primaryKey = 'notification_id';
            $this->hasReportIdColumn = true;
        }
    }
    
    public function create($data) {
        $this->ensureSchemaMetadata();

        if ($this->hasReportIdColumn) {
            $query = "INSERT INTO notifications 
                      (user_id, report_id, title, message, is_read, created_at) 
                      VALUES (?, ?, ?, ?, 0, NOW())";

            $stmt = $this->pdo->prepare($query);
            return $stmt->execute([
                $data['user_id'],
                $data['report_id'] ?? null,
                $data['title'],
                $data['message']
            ]);
        }

        $query = "INSERT INTO notifications 
                  (user_id, title, message, is_read, created_at) 
                  VALUES (?, ?, ?, 0, NOW())";

        $stmt = $this->pdo->prepare($query);
        return $stmt->execute([
            $data['user_id'],
            $data['title'],
            $data['message']
        ]);
    }
    
    public function getUnread($userId, $limit = 10) {
        $this->ensureSchemaMetadata();

        if ($this->hasReportIdColumn) {
            $query = "SELECT n.*, n.{$this->primaryKey} AS notification_id,
                             r.title as report_title, r.priority, n.report_id
                      FROM notifications n
                      LEFT JOIN maintenance_reports r ON n.report_id = r.report_id
                      WHERE n.user_id = ? AND n.is_read = 0
                      ORDER BY n.created_at DESC
                      LIMIT ?";
        } else {
            $query = "SELECT n.*, n.{$this->primaryKey} AS notification_id,
                             NULL AS report_title, NULL AS priority, NULL AS report_id
                      FROM notifications n
                      WHERE n.user_id = ? AND n.is_read = 0
                      ORDER BY n.created_at DESC
                      LIMIT ?";
        }
        
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    }
    
    public function getAll($userId, $limit = 50, $offset = 0) {
        $this->ensureSchemaMetadata();

        if ($this->hasReportIdColumn) {
            $query = "SELECT n.*, n.{$this->primaryKey} AS notification_id,
                             r.title as report_title, r.priority, n.report_id
                      FROM notifications n
                      LEFT JOIN maintenance_reports r ON n.report_id = r.report_id
                      WHERE n.user_id = ?
                      ORDER BY n.created_at DESC
                      LIMIT ? OFFSET ?";
        } else {
            $query = "SELECT n.*, n.{$this->primaryKey} AS notification_id,
                             NULL AS report_title, NULL AS priority, NULL AS report_id
                      FROM notifications n
                      WHERE n.user_id = ?
                      ORDER BY n.created_at DESC
                      LIMIT ? OFFSET ?";
        }
        
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$userId, $limit, $offset]);
        return $stmt->fetchAll();
    }
    
    /**
     * TASK 52 — $userId is required and scopes the UPDATE so an authenticated
     * user can only mark their OWN notifications as read. Previously this
     * trusted the client-supplied notification_id alone: any logged-in user
     * could pass another user's notification_id and mark it read (an IDOR).
     * The Laravel API's equivalent endpoint (NotificationController) has
     * always scoped by the session user; this legacy endpoint did not.
     */
    public function markAsRead($notificationId, $userId) {
        $this->ensureSchemaMetadata();

        $query = "UPDATE notifications SET is_read = 1 WHERE {$this->primaryKey} = ? AND user_id = ?";
        $stmt = $this->pdo->prepare($query);
        return $stmt->execute([$notificationId, $userId]);
    }
    
    public function markAllAsRead($userId) {
        $query = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0";
        $stmt = $this->pdo->prepare($query);
        return $stmt->execute([$userId]);
    }
    
    public function countUnread($userId) {
        $query = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        return $result['count'] ?? 0;
    }
    
    /**
     * TASK 52 — $userId is required and scopes the DELETE for the same
     * ownership reason documented on markAsRead() above: without it, any
     * authenticated user could delete another user's notification by guessing
     * or enumerating notification_id.
     */
    public function delete($notificationId, $userId) {
        $this->ensureSchemaMetadata();

        $query = "DELETE FROM notifications WHERE {$this->primaryKey} = ? AND user_id = ?";
        $stmt = $this->pdo->prepare($query);
        return $stmt->execute([$notificationId, $userId]);
    }
}