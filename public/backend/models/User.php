<?php
/**
 * User Model
 * Represents a user in the system
 */

class User {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function create($data) {
        $query = "INSERT INTO users (full_name, email, password, role, department_id, status, created_at) 
                  VALUES (?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $this->pdo->prepare($query);
        return $stmt->execute([
            $data['full_name'],
            $data['email'],
            password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]),
            $data['role'],
            $data['department_id'] ?? null,
            STATUS_ACTIVE
        ]);
    }
    
    public function findById($userId) {
        $query = "SELECT user_id, full_name, email, role, department_id, status, created_at 
                  FROM users WHERE user_id = ?";
        
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }
    
    public function findByEmail($email) {
        $query = "SELECT user_id, full_name, email, password, role, department_id, status 
                  FROM users WHERE email = ?";
        
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$email]);
        return $stmt->fetch();
    }
    
    public function update($userId, $data) {
        $updates = [];
        $values = [];
        
        foreach ($data as $key => $value) {
            if (in_array($key, ['full_name', 'email', 'role', 'department_id', 'status'])) {
                $updates[] = "{$key} = ?";
                $values[] = $value;
            }
        }
        
        if (empty($updates)) {
            return false;
        }
        
        $values[] = $userId;
        $query = "UPDATE users SET " . implode(', ', $updates) . " WHERE user_id = ?";
        
        $stmt = $this->pdo->prepare($query);
        return $stmt->execute($values);
    }
    
    public function delete($userId) {
        $query = "DELETE FROM users WHERE user_id = ?";
        $stmt = $this->pdo->prepare($query);
        return $stmt->execute([$userId]);
    }
    
    public function getAllByRole($role) {
        $query = "SELECT user_id, full_name, email, role, department_id, status 
                  FROM users WHERE role = ? ORDER BY full_name ASC";
        
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$role]);
        return $stmt->fetchAll();
    }
    
    public function getAllByDepartment($departmentId) {
        $query = "SELECT user_id, full_name, email, role, status 
                  FROM users WHERE department_id = ? ORDER BY full_name ASC";
        
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$departmentId]);
        return $stmt->fetchAll();
    }
}
