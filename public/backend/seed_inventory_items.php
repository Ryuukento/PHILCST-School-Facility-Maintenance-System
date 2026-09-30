<?php
/**
 * Seed Inventory Items
 * Populates inventory items for each category in SFMS
 */

require_once __DIR__ . '/_dev_guard.php';
require_once __DIR__ . '/bootstrap.php';

// Sample room_id para sa items (assuming may default room or using 1)
$sampleRoomId = 1;

try {
    // Get inventory categories
    $categoriesStmt = $pdo->query("SELECT id, name FROM inventory_categories ORDER BY id");
    $categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!$categories) {
        echo "❌ No inventory categories found. Please create categories first.\n";
        exit(1);
    }

    // Map category names to items
    $itemsByCategory = [
        'electrical' => [
            'Extension Cords',
            'Circuit Breakers',
            'Fluorescent Tubes',
            'Outlets'
        ],
        'it & electronics' => [
            'Monitors',
            'Projectors',
            'Printers',
            'Switches',
            'Cables'
        ],
        'furniture' => [
            'Chairs',
            'Desks',
            'Cabinets',
            'Shelves'
        ],
        'plumbing' => [
            'Faucets',
            'Pipes',
            'Valves',
            'Toilets'
        ],
        'classroom' => [
            'Markers',
            'Boards',
            'Teaching Materials'
        ]
    ];

    $insertedCount = 0;
    
    foreach ($categories as $category) {
        $categoryNameLower = strtolower((string)$category['name']);
        $categoryId = (int)$category['id'];
        
        if (!isset($itemsByCategory[$categoryNameLower])) {
            echo "⏭️  Skipping category: {$category['name']} (no items defined)\n";
            continue;
        }

        $items = $itemsByCategory[$categoryNameLower];
        
        foreach ($items as $itemName) {
            // Check if item already exists
            $checkStmt = $pdo->prepare("SELECT id FROM items WHERE category_id = ? AND LOWER(name) = ? LIMIT 1");
            $checkStmt->execute([$categoryId, strtolower($itemName)]);
            
            if ($checkStmt->fetch()) {
                echo "⏭️  Item already exists: {$itemName} in {$category['name']}\n";
                continue;
            }

            // Insert new item
            $insertStmt = $pdo->prepare(
                "INSERT INTO items (room_id, category_id, name, status, quantity, description, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())"
            );
            
            $insertStmt->execute([
                $sampleRoomId,
                $categoryId,
                $itemName,
                'available',
                10, // default quantity
                "Item in {$category['name']}"
            ]);

            echo "✅ Added: {$itemName} to {$category['name']}\n";
            $insertedCount++;
        }
    }

    echo "\n" . str_repeat('=', 50) . "\n";
    echo "✅ SUMMARY: Successfully inserted {$insertedCount} inventory items\n";
    echo str_repeat('=', 50) . "\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
