<?php
/**
 * Floor Model
 */

class Floor {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getAll() {
        $stmt = $this->pdo->prepare(
            "SELECT f.id, f.name, f.building_id, b.name as building_name, f.created_at
             FROM floors f
             LEFT JOIN buildings b ON f.building_id = b.id
             ORDER BY b.name, f.name"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getByBuilding($buildingId) {
        $stmt = $this->pdo->prepare(
            "SELECT f.id, f.name, f.description,
                    COALESCE(COUNT(DISTINCT r.id), 0) as room_count,
                    COALESCE(SUM(COALESCE(i.quantity, 0)), 0) as item_count
             FROM floors f
             LEFT JOIN rooms r ON f.id = r.floor_id
             LEFT JOIN items i ON r.id = i.room_id
             WHERE f.building_id = ?
             GROUP BY f.id, f.name, f.description
             ORDER BY f.name"
        );
        $stmt->execute([$buildingId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById($id) {
        $stmt = $this->pdo->prepare(
            "SELECT f.id, f.name, f.building_id, b.name as building_name, f.created_at
             FROM floors f
             LEFT JOIN buildings b ON f.building_id = b.id
             WHERE f.id = ?"
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function existsByBuildingAndName($buildingId, $name, $excludeId = null) {
        if ($excludeId) {
            $stmt = $this->pdo->prepare("SELECT id FROM floors WHERE building_id = ? AND name = ? AND id != ? LIMIT 1");
            $stmt->execute([$buildingId, $name, $excludeId]);
        } else {
            $stmt = $this->pdo->prepare("SELECT id FROM floors WHERE building_id = ? AND name = ? LIMIT 1");
            $stmt->execute([$buildingId, $name]);
        }
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($buildingId, $name) {
        $stmt = $this->pdo->prepare(
            "INSERT INTO floors (building_id, name, created_at, updated_at)
             VALUES (?, ?, NOW(), NOW())"
        );
        $stmt->execute([$buildingId, $name]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update($id, $buildingId, $name) {
        $stmt = $this->pdo->prepare(
            "UPDATE floors
             SET building_id = ?, name = ?, updated_at = NOW()
             WHERE id = ?"
        );
        return $stmt->execute([$buildingId, $name, $id]);
    }

    public function delete($id) {
        $stmt = $this->pdo->prepare("DELETE FROM floors WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function hasRooms($id) {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM rooms WHERE floor_id = ?");
        $stmt->execute([$id]);
        return ((int)$stmt->fetchColumn()) > 0;
    }
}
