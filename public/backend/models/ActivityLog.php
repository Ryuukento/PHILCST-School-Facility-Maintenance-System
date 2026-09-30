<?php
/**
 * ActivityLog Model
 * Tracks user activities in the system
 */

class ActivityLog {
    private $pdo;
    private $primaryKey = 'id';
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function log($userId, $action, $entityType, $entityId = null, $details = null) {
        $query = "INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details, created_at) 
                  VALUES (?, ?, ?, ?, ?, NOW())";
        
        $stmt = $this->pdo->prepare($query);
        return $stmt->execute([
            $userId,
            $action,
            $entityType,
            $entityId,
            $details ? json_encode($details) : null
        ]);
    }
    
    public function getByUser($userId, $limit = 50, $offset = 0) {
        $query = "SELECT id as log_id, action, entity_type, entity_id, details, created_at
                  FROM activity_logs 
                  WHERE user_id = ? 
                  ORDER BY created_at DESC 
                  LIMIT ? OFFSET ?";
        
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$userId, $limit, $offset]);
        return $stmt->fetchAll();
    }
    
    public function getByEntity($entityType, $entityId) {
        $query = "SELECT id as log_id, user_id, action, details, created_at
                  FROM activity_logs 
                  WHERE entity_type = ? AND entity_id = ?
                  ORDER BY created_at DESC";
        
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$entityType, $entityId]);
        return $stmt->fetchAll();
    }
    
    public function getAll($limit = 100, $offset = 0) {
        $query = "SELECT id as log_id, user_id, action, entity_type, entity_id, created_at
                  FROM activity_logs 
                  ORDER BY created_at DESC 
                  LIMIT ? OFFSET ?";
        
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll();
    }
}
