<?php
/**
 * Inventory Category Model
 */

class InventoryCategory {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getAll($activeOnly = false) {
        $sql = "SELECT c.*,
                       COUNT(i.id) AS total_items,
                       COALESCE(SUM(CASE WHEN i.item_type = 'inventory_stock' THEN i.quantity ELSE 0 END), 0) AS total_stock_quantity
                FROM inventory_categories c
                LEFT JOIN items i ON i.category_id = c.id";
        $params = [];

        if ($activeOnly) {
            $sql .= " WHERE c.is_active = 1";
        }

        $sql .= " GROUP BY c.id, c.name, c.code, c.default_low_stock_threshold, c.allow_threshold_override, c.is_active, c.sort_order, c.created_at, c.updated_at
                  ORDER BY c.sort_order ASC, c.name ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM inventory_categories WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function existsByName($name, $excludeId = null) {
        if ($excludeId) {
            $stmt = $this->pdo->prepare("SELECT id FROM inventory_categories WHERE LOWER(name) = LOWER(?) AND id != ? LIMIT 1");
            $stmt->execute([$name, (int)$excludeId]);
        } else {
            $stmt = $this->pdo->prepare("SELECT id FROM inventory_categories WHERE LOWER(name) = LOWER(?) LIMIT 1");
            $stmt->execute([$name]);
        }

        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * TASK 55 — inventory_categories.code carries its own unique index,
     * independent of `name` (see
     * 2026_04_07_001000_add_inventory_categories_and_thresholds.php). See
     * FacilityService::createInventoryCategory()/updateInventoryCategory()
     * for why this must be checked before insert/update. $code is expected
     * pre-normalized to lowercase by the caller (or null when absent); the
     * LOWER() comparison here is defense in depth, matching existsByName()'s
     * style above.
     */
    public function existsByCode($code, $excludeId = null) {
        if ($code === null || $code === '') {
            return false;
        }

        if ($excludeId) {
            $stmt = $this->pdo->prepare("SELECT id FROM inventory_categories WHERE LOWER(code) = LOWER(?) AND id != ? LIMIT 1");
            $stmt->execute([$code, (int)$excludeId]);
        } else {
            $stmt = $this->pdo->prepare("SELECT id FROM inventory_categories WHERE LOWER(code) = LOWER(?) LIMIT 1");
            $stmt->execute([$code]);
        }

        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($name, $code, $defaultThreshold, $allowOverride, $isActive, $sortOrder) {
        $stmt = $this->pdo->prepare(
            "INSERT INTO inventory_categories (name, code, default_low_stock_threshold, allow_threshold_override, is_active, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        $stmt->execute([
            $name,
            $code,
            $defaultThreshold,
            (int)$allowOverride,
            (int)$isActive,
            (int)$sortOrder
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function update($id, $name, $code, $defaultThreshold, $allowOverride, $isActive, $sortOrder) {
        $stmt = $this->pdo->prepare(
            "UPDATE inventory_categories
             SET name = ?, code = ?, default_low_stock_threshold = ?, allow_threshold_override = ?, is_active = ?, sort_order = ?, updated_at = NOW()
             WHERE id = ?"
        );

        return $stmt->execute([
            $name,
            $code,
            $defaultThreshold,
            (int)$allowOverride,
            (int)$isActive,
            (int)$sortOrder,
            (int)$id
        ]);
    }

    public function delete($id) {
        $stmt = $this->pdo->prepare("DELETE FROM inventory_categories WHERE id = ?");
        return $stmt->execute([(int)$id]);
    }

    public function hasItems($id) {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM items WHERE category_id = ?");
        $stmt->execute([(int)$id]);
        return ((int)$stmt->fetchColumn()) > 0;
    }
}
