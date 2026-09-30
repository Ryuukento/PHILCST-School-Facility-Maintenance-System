<?php
/**
 * Building Model
 */

class Building {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getAllWithCounts() {
        $stmt = $this->pdo->prepare(
            "SELECT b.id, b.name, b.description, b.created_at,
                    COUNT(DISTINCT f.id) as floor_count,
                    COUNT(DISTINCT r.id) as room_count
             FROM buildings b
             LEFT JOIN floors f ON b.id = f.building_id
             LEFT JOIN rooms r ON b.id = r.building_id
             GROUP BY b.id, b.name, b.description, b.created_at
             ORDER BY b.created_at DESC"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByIdWithCounts($id) {
        $stmt = $this->pdo->prepare(
            "SELECT b.id, b.name, b.description, b.created_at,
                    COUNT(DISTINCT f.id) as floor_count,
                    COUNT(DISTINCT r.id) as room_count
             FROM buildings b
             LEFT JOIN floors f ON b.id = f.building_id
             LEFT JOIN rooms r ON b.id = r.building_id
             WHERE b.id = ?
             GROUP BY b.id, b.name, b.description, b.created_at"
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function existsByName($name, $excludeId = null) {
        if ($excludeId) {
            $stmt = $this->pdo->prepare("SELECT id FROM buildings WHERE name = ? AND id != ? LIMIT 1");
            $stmt->execute([$name, $excludeId]);
        } else {
            $stmt = $this->pdo->prepare("SELECT id FROM buildings WHERE name = ? LIMIT 1");
            $stmt->execute([$name]);
        }
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($name, $description) {
        $stmt = $this->pdo->prepare(
            "INSERT INTO buildings (name, description, created_at, updated_at)
             VALUES (?, ?, NOW(), NOW())"
        );
        $stmt->execute([$name, $description]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update($id, $name, $description) {
        $stmt = $this->pdo->prepare(
            "UPDATE buildings
             SET name = ?, description = ?, updated_at = NOW()
             WHERE id = ?"
        );
        return $stmt->execute([$name, $description, $id]);
    }

    public function delete($id) {
        $stmt = $this->pdo->prepare("DELETE FROM buildings WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function hasFloors($id) {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM floors WHERE building_id = ?");
        $stmt->execute([$id]);
        return ((int)$stmt->fetchColumn()) > 0;
    }

    public function hasRooms($id) {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM rooms WHERE building_id = ?");
        $stmt->execute([$id]);
        return ((int)$stmt->fetchColumn()) > 0;
    }
}
