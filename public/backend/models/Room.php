<?php
/**
 * Room Model
 */

class Room {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getAll() {
        $stmt = $this->pdo->prepare(
            "SELECT r.id, r.name, r.capacity, r.building_id, r.floor_id,
                    b.name as building_name, f.name as floor_name, r.created_at
             FROM rooms r
             LEFT JOIN buildings b ON r.building_id = b.id
             LEFT JOIN floors f ON r.floor_id = f.id
             ORDER BY b.name, f.name, r.name"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getByBuilding($buildingId) {
        $stmt = $this->pdo->prepare(
            "SELECT r.id, r.name, r.capacity, r.floor_id,
                    f.name as floor_name
             FROM rooms r
             LEFT JOIN floors f ON r.floor_id = f.id
             WHERE r.building_id = ?
             ORDER BY r.name"
        );
        $stmt->execute([$buildingId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getByFloor($floorId) {
        $stmt = $this->pdo->prepare(
            "SELECT id, name, capacity, building_id
             FROM rooms
             WHERE floor_id = ?
             ORDER BY name"
        );
        $stmt->execute([$floorId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById($id) {
        $stmt = $this->pdo->prepare(
            "SELECT r.id, r.name, r.capacity, r.building_id, r.floor_id,
                    b.name as building_name, f.name as floor_name, r.created_at
             FROM rooms r
             LEFT JOIN buildings b ON r.building_id = b.id
             LEFT JOIN floors f ON r.floor_id = f.id
             WHERE r.id = ?"
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function existsByFloorAndName($floorId, $name, $excludeId = null) {
        if ($excludeId) {
            $stmt = $this->pdo->prepare("SELECT id FROM rooms WHERE floor_id = ? AND name = ? AND id != ? LIMIT 1");
            $stmt->execute([$floorId, $name, $excludeId]);
        } else {
            $stmt = $this->pdo->prepare("SELECT id FROM rooms WHERE floor_id = ? AND name = ? LIMIT 1");
            $stmt->execute([$floorId, $name]);
        }
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * TASK 55 — mirrors the `rooms` table's SEPARATE unique(building_id,
     * name) index (2026_03_27_000300_create_facility_tables.php), which
     * spans every floor in the building — a different, wider constraint
     * than existsByFloorAndName()'s floor-scoped check above. Both must be
     * checked before insert/update; see FacilityService::createRoom() for
     * why.
     */
    public function existsByBuildingAndName($buildingId, $name, $excludeId = null) {
        if ($excludeId) {
            $stmt = $this->pdo->prepare("SELECT id FROM rooms WHERE building_id = ? AND name = ? AND id != ? LIMIT 1");
            $stmt->execute([$buildingId, $name, $excludeId]);
        } else {
            $stmt = $this->pdo->prepare("SELECT id FROM rooms WHERE building_id = ? AND name = ? LIMIT 1");
            $stmt->execute([$buildingId, $name]);
        }
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($buildingId, $floorId, $name, $capacity) {
        $stmt = $this->pdo->prepare(
            "INSERT INTO rooms (building_id, floor_id, name, capacity, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())"
        );
        $stmt->execute([$buildingId, $floorId, $name, $capacity]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update($id, $buildingId, $floorId, $name, $capacity) {
        $stmt = $this->pdo->prepare(
            "UPDATE rooms
             SET building_id = ?, floor_id = ?, name = ?, capacity = ?, updated_at = NOW()
             WHERE id = ?"
        );
        return $stmt->execute([$buildingId, $floorId, $name, $capacity, $id]);
    }

    public function delete($id) {
        $stmt = $this->pdo->prepare("DELETE FROM rooms WHERE id = ?");
        return $stmt->execute([$id]);
    }
}
