<?php
/**
 * Update/Seed Inventory Categories and Items
 */

require_once __DIR__ . '/_dev_guard.php';
require_once __DIR__ . '/bootstrap.php';

try {
    echo "=== INVENTORY SETUP ===\n\n";
    
    // Step 1: Check existing categories
    echo "Step 1: Checking existing categories...\n";
    $stmt = $pdo->query("SELECT id, name FROM inventory_categories ORDER BY id");
    $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($existing as $cat) {
        echo "  - ID {$cat['id']}: {$cat['name']}\n";
    }
    
    // Step 2: Define desired categories
    $desiredCategories = [
        'Electrical',
        'IT & Electronics',
        'Furniture',
        'Plumbing',
        'Classroom'
    ];
    
    // Step 3: Delete old categories and re-seed
    echo "\nStep 2: Clearing and re-seeding categories...\n";
    $pdo->exec("DELETE FROM inventory_categories");
    
    foreach ($desiredCategories as $index => $catName) {
        $insertStmt = $pdo->prepare(
            "INSERT INTO inventory_categories (name, default_low_stock_threshold, allow_threshold_override, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())"
        );
        $insertStmt->execute([
            $catName,
            10, // default low stock threshold
            1,  // allow threshold override
            $index + 1
        ]);
        echo "  ✅ Added: {$catName}\n";
    }
    
    // Step 4: Define items for each category
    echo "\nStep 3: Adding items to categories...\n";
    
    $itemsByCategory = [
        'Electrical' => [
            'Extension Cords',
            'Circuit Breakers',
            'Fluorescent Tubes',
            'Outlets'
        ],
        'IT & Electronics' => [
            'Monitors',
            'Projectors',
            'Printers',
            'Switches',
            'Cables'
        ],
        'Furniture' => [
            'Chairs',
            'Desks',
            'Cabinets',
            'Shelves'
        ],
        'Plumbing' => [
            'Faucets',
            'Pipes',
            'Valves',
            'Toilets'
        ],
        'Classroom' => [
            'Markers',
            'Boards',
            'Teaching Materials'
        ]
    ];
    
    $sampleRoomId = 1;
    $insertedItems = 0;
    
    foreach ($itemsByCategory as $categoryName => $items) {
        $catStmt = $pdo->prepare("SELECT id FROM inventory_categories WHERE name = ? LIMIT 1");
        $catStmt->execute([$categoryName]);
        $cat = $catStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$cat) {
            echo "  ❌ Category not found: {$categoryName}\n";
            continue;
        }
        
        $categoryId = (int)$cat['id'];
        echo "  📂 {$categoryName}:\n";
        
        foreach ($items as $itemName) {
            $itemStmt = $pdo->prepare(
                "INSERT INTO items (room_id, category_id, name, status, quantity, description, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())"
            );
            $itemStmt->execute([
                $sampleRoomId,
                $categoryId,
                $itemName,
                'available',
                10,
                "Item in {$categoryName}"
            ]);
            echo "    ✅ {$itemName}\n";
            $insertedItems++;
        }
    }
    
    echo "\n" . str_repeat('=', 50) . "\n";
    echo "✅ SUCCESS: Added 5 categories with {$insertedItems} items total\n";
    echo str_repeat('=', 50) . "\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
