<?php
/**
 * Database Setup/Migration
 * Creates necessary tables for buildings and rooms
 */

require_once __DIR__ . '/_dev_guard.php';
require_once __DIR__ . '/config/database.php';

try {
    $pdo = getDBConnection();
    
    // Create buildings table (first in order)
    $createBuildingsTable = "
    CREATE TABLE IF NOT EXISTS buildings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL UNIQUE,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $pdo->exec($createBuildingsTable);
    echo "✓ Buildings table created/verified\n";
    
    // Create floors table (second: depends on buildings)
    $createFloorsTable = "
    CREATE TABLE IF NOT EXISTS floors (
        id INT PRIMARY KEY AUTO_INCREMENT,
        building_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
        UNIQUE KEY unique_floor_per_building (building_id, name),
        INDEX idx_building (building_id),
        INDEX idx_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    $pdo->exec($createFloorsTable);
    echo "✓ Floors table created/verified\n";

    // Create rooms table (third: depends on buildings and floors)
    $createRoomsTable = "
    CREATE TABLE IF NOT EXISTS rooms (
        id INT PRIMARY KEY AUTO_INCREMENT,
        building_id INT NOT NULL,
        floor_id INT DEFAULT NULL,
        name VARCHAR(255) NOT NULL,
        capacity INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
        FOREIGN KEY (floor_id) REFERENCES floors(id) ON DELETE CASCADE,
        UNIQUE KEY unique_room_per_building (building_id, name),
        UNIQUE KEY unique_room_per_floor (floor_id, name),
        INDEX idx_building (building_id),
        INDEX idx_floor (floor_id),
        INDEX idx_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $pdo->exec($createRoomsTable);
    echo "✓ Rooms table created/verified\n";
    
    echo "\n✓ Database setup completed successfully!\n";

    // Create items table (fourth: depends on rooms)
    $createItemsTable = "
    CREATE TABLE IF NOT EXISTS items (
        id INT PRIMARY KEY AUTO_INCREMENT,
        room_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        status ENUM('available','damaged','low_stock','out_of_stock','maintenance') DEFAULT 'available',
        quantity INT DEFAULT 1,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
        INDEX idx_room (room_id),
        INDEX idx_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    $pdo->exec($createItemsTable);
    echo "✓ Items table created/verified\n";
    
} catch (PDOException $e) {
    echo "✗ Error setting up database: " . $e->getMessage() . "\n";
    exit(1);
}

//Redirect to dashboard after setup
header('Location: /School_Facility_Maintenance_System/frontend/pages/dashboard.php');
exit;
?>
