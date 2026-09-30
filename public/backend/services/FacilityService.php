<?php
/**
 * Facility Service
 * Centralized business logic for buildings, floors, rooms, and items.
 */

class FacilityService {
    private $pdo;
    private $buildingModel;
    private $floorModel;
    private $roomModel;
    private $itemModel;
    private $inventoryCategoryModel;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->buildingModel = new Building($pdo);
        $this->floorModel = new Floor($pdo);
        $this->roomModel = new Room($pdo);
        $this->itemModel = new Item($pdo);
        $this->inventoryCategoryModel = new InventoryCategory($pdo);
    }

    public function listBuildings() {
        try {
            $rows = $this->buildingModel->getAllWithCounts();
            return ['success' => true, 'message' => 'Buildings retrieved successfully', 'buildings' => $rows];
        } catch (Exception $e) {
            Logger::error('Failed to list buildings', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to retrieve buildings', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function getBuilding($id) {
        if (!$id) {
            return ['success' => false, 'message' => 'Building ID is required', 'code' => Response::HTTP_BAD_REQUEST];
        }

        try {
            $row = $this->buildingModel->findByIdWithCounts($id);
            if (!$row) {
                return ['success' => false, 'message' => 'Building not found', 'code' => Response::HTTP_NOT_FOUND];
            }
            return ['success' => true, 'message' => 'Building retrieved successfully', 'building' => $row];
        } catch (Exception $e) {
            Logger::error('Failed to get building', ['id' => $id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to retrieve building', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function createBuilding($data) {
        $name = trim((string)($data['name'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));

        if ($name === '') {
            return ['success' => false, 'message' => 'Building name is required', 'code' => Response::HTTP_BAD_REQUEST];
        }

        try {
            if ($this->buildingModel->existsByName($name)) {
                return ['success' => false, 'message' => 'Building with this name already exists', 'code' => Response::HTTP_CONFLICT];
            }

            $id = $this->buildingModel->create($name, $description);
            return ['success' => true, 'message' => 'Building created successfully', 'id' => $id, 'name' => $name];
        } catch (Exception $e) {
            // TASK 55 — Security & Input Validation Hardening: this used to
            // return 'Database error: ' . $e->getMessage() straight to the
            // HTTP client, unlike every sibling update/delete method in this
            // class (which already return a generic message here). A raw
            // PDOException message can contain the SQLSTATE code, the
            // literal SQL, and column/constraint names — real internal
            // schema detail with no legitimate reason to reach an API
            // response. The full message is still captured server-side via
            // Logger::error() above; only the client-facing copy changes.
            Logger::error('Failed to create building', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to create building', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function updateBuilding($data) {
        $id = (int)($data['id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));

        if ($id <= 0 || $name === '') {
            return ['success' => false, 'message' => 'Building ID and name are required', 'code' => Response::HTTP_BAD_REQUEST];
        }

        try {
            if ($this->buildingModel->existsByName($name, $id)) {
                return ['success' => false, 'message' => 'Building with this name already exists', 'code' => Response::HTTP_CONFLICT];
            }

            $this->buildingModel->update($id, $name, $description);
            return ['success' => true, 'message' => 'Building updated successfully'];
        } catch (Exception $e) {
            Logger::error('Failed to update building', ['id' => $id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to update building', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function deleteBuilding($id) {
        if (!$id) {
            return ['success' => false, 'message' => 'Building ID is required', 'code' => Response::HTTP_BAD_REQUEST];
        }

        try {
            if ($this->buildingModel->hasFloors($id)) {
                return ['success' => false, 'message' => 'Cannot delete building with existing floors', 'code' => Response::HTTP_CONFLICT];
            }
            if ($this->buildingModel->hasRooms($id)) {
                return ['success' => false, 'message' => 'Cannot delete building with existing rooms', 'code' => Response::HTTP_CONFLICT];
            }

            $this->buildingModel->delete($id);
            return ['success' => true, 'message' => 'Building deleted successfully'];
        } catch (Exception $e) {
            Logger::error('Failed to delete building', ['id' => $id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to delete building', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function listFloors() {
        try {
            $rows = $this->floorModel->getAll();
            return ['success' => true, 'message' => 'Floors retrieved successfully', 'floors' => $rows];
        } catch (Exception $e) {
            Logger::error('Failed to list floors', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to retrieve floors', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function listFloorsByBuilding($buildingId) {
        if (!$buildingId) {
            return ['success' => false, 'message' => 'Building ID is required', 'code' => Response::HTTP_BAD_REQUEST];
        }

        try {
            $rows = $this->floorModel->getByBuilding($buildingId);
            return ['success' => true, 'message' => 'Floors retrieved successfully', 'floors' => $rows];
        } catch (Exception $e) {
            Logger::error('Failed to list floors by building', ['building_id' => $buildingId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to retrieve floors', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function getFloor($id) {
        if (!$id) {
            return ['success' => false, 'message' => 'Floor ID is required', 'code' => Response::HTTP_BAD_REQUEST];
        }

        try {
            $row = $this->floorModel->findById($id);
            if (!$row) {
                return ['success' => false, 'message' => 'Floor not found', 'code' => Response::HTTP_NOT_FOUND];
            }
            return ['success' => true, 'message' => 'Floor retrieved successfully', 'floor' => $row];
        } catch (Exception $e) {
            Logger::error('Failed to get floor', ['id' => $id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to retrieve floor', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function createFloor($data) {
        $buildingId = (int)($data['building_id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));

        if ($buildingId <= 0 || $name === '') {
            return ['success' => false, 'message' => 'Building ID and floor name are required', 'code' => Response::HTTP_BAD_REQUEST];
        }

        try {
            if (!$this->buildingModel->findByIdWithCounts($buildingId)) {
                return ['success' => false, 'message' => 'Building not found', 'code' => Response::HTTP_NOT_FOUND];
            }

            if ($this->floorModel->existsByBuildingAndName($buildingId, $name)) {
                return ['success' => false, 'message' => 'Floor with this name already exists in this building', 'code' => Response::HTTP_CONFLICT];
            }

            $id = $this->floorModel->create($buildingId, $name);
            return ['success' => true, 'message' => 'Floor created successfully', 'id' => $id, 'name' => $name];
        } catch (Exception $e) {
            // TASK 55 — see the identical fix/rationale in createBuilding()
            // above: never echo a raw exception message to the client.
            Logger::error('Failed to create floor', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to create floor', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function updateFloor($data) {
        $id = (int)($data['id'] ?? 0);
        $buildingId = (int)($data['building_id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));

        if ($id <= 0 || $buildingId <= 0 || $name === '') {
            return ['success' => false, 'message' => 'Floor ID, building ID and name are required', 'code' => Response::HTTP_BAD_REQUEST];
        }

        try {
            if (!$this->buildingModel->findByIdWithCounts($buildingId)) {
                return ['success' => false, 'message' => 'Building not found', 'code' => Response::HTTP_NOT_FOUND];
            }

            if ($this->floorModel->existsByBuildingAndName($buildingId, $name, $id)) {
                return ['success' => false, 'message' => 'Floor with this name already exists in this building', 'code' => Response::HTTP_CONFLICT];
            }

            $this->floorModel->update($id, $buildingId, $name);
            return ['success' => true, 'message' => 'Floor updated successfully'];
        } catch (Exception $e) {
            Logger::error('Failed to update floor', ['id' => $id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to update floor', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function deleteFloor($id) {
        if (!$id) {
            return ['success' => false, 'message' => 'Floor ID is required', 'code' => Response::HTTP_BAD_REQUEST];
        }

        try {
            if ($this->floorModel->hasRooms($id)) {
                return ['success' => false, 'message' => 'Cannot delete floor with existing rooms', 'code' => Response::HTTP_CONFLICT];
            }
            $this->floorModel->delete($id);
            return ['success' => true, 'message' => 'Floor deleted successfully'];
        } catch (Exception $e) {
            Logger::error('Failed to delete floor', ['id' => $id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to delete floor', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function listRooms() {
        try {
            $rows = $this->roomModel->getAll();
            return ['success' => true, 'message' => 'Rooms retrieved successfully', 'rooms' => $rows];
        } catch (Exception $e) {
            Logger::error('Failed to list rooms', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to retrieve rooms', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function listRoomsByBuilding($buildingId) {
        if (!$buildingId) {
            return ['success' => false, 'message' => 'Building ID is required', 'code' => Response::HTTP_BAD_REQUEST];
        }

        try {
            $rows = $this->roomModel->getByBuilding($buildingId);
            return ['success' => true, 'message' => 'Rooms retrieved successfully', 'rooms' => $rows];
        } catch (Exception $e) {
            Logger::error('Failed to list rooms by building', ['building_id' => $buildingId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to retrieve rooms', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function listRoomsByFloor($floorId) {
        if (!$floorId) {
            return ['success' => false, 'message' => 'Floor ID is required', 'code' => Response::HTTP_BAD_REQUEST];
        }

        try {
            $rows = $this->roomModel->getByFloor($floorId);
            return ['success' => true, 'message' => 'Rooms retrieved successfully', 'rooms' => $rows];
        } catch (Exception $e) {
            Logger::error('Failed to list rooms by floor', ['floor_id' => $floorId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to retrieve rooms', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function getRoom($id) {
        if (!$id) {
            return ['success' => false, 'message' => 'Room ID is required', 'code' => Response::HTTP_BAD_REQUEST];
        }

        try {
            $row = $this->roomModel->findById($id);
            if (!$row) {
                return ['success' => false, 'message' => 'Room not found', 'code' => Response::HTTP_NOT_FOUND];
            }
            return ['success' => true, 'message' => 'Room retrieved successfully', 'room' => $row];
        } catch (Exception $e) {
            Logger::error('Failed to get room', ['id' => $id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to retrieve room', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function createRoom($data) {
        $buildingId = (int)($data['building_id'] ?? 0);
        $floorId = (int)($data['floor_id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        $capacity = $data['capacity'] ?? null;

        if ($buildingId <= 0 || $floorId <= 0 || $name === '') {
            return ['success' => false, 'message' => 'Building, floor and room name are required', 'code' => Response::HTTP_BAD_REQUEST];
        }

        if ($capacity !== null && $capacity !== '') {
            $capacity = (int)$capacity;
            if ($capacity < 1 || $capacity > 60) {
                return ['success' => false, 'message' => 'Room capacity must be between 1 and 60', 'code' => Response::HTTP_BAD_REQUEST];
            }
        }

        try {
            if (!$this->buildingModel->findByIdWithCounts($buildingId)) {
                return ['success' => false, 'message' => 'Building not found', 'code' => Response::HTTP_NOT_FOUND];
            }

            $floor = $this->floorModel->findById($floorId);
            if (!$floor || (int)$floor['building_id'] !== $buildingId) {
                return ['success' => false, 'message' => 'Floor not found or does not belong to building', 'code' => Response::HTTP_BAD_REQUEST];
            }

            if ($this->roomModel->existsByFloorAndName($floorId, $name)) {
                return ['success' => false, 'message' => 'Room with this name already exists on this floor', 'code' => Response::HTTP_CONFLICT];
            }

            // TASK 55 — Security & Input Validation Hardening: the check
            // above only reflects HALF of the real DB constraint. The
            // `rooms` table (2026_03_27_000300_create_facility_tables.php)
            // carries TWO separate unique indexes:
            // unique(floor_id, name) — checked above — AND, independently,
            // unique(building_id, name) spanning every floor in the
            // building. Two different floors of the SAME building could
            // each pass the floor-scoped check above and still collide on
            // the building-scoped one at INSERT time (proven directly: a
            // legitimate "Room 101" on Floor A followed by "Room 101" on
            // Floor B of the same building threw an uncaught PDOException
            // instead of returning a clean conflict). That exception's raw
            // message used to be echoed straight to the client below — this
            // check closes the validation gap that made that reachable at
            // all in ordinary (non-race-condition) use.
            if ($this->roomModel->existsByBuildingAndName($buildingId, $name)) {
                return ['success' => false, 'message' => 'Room with this name already exists in this building', 'code' => Response::HTTP_CONFLICT];
            }

            $id = $this->roomModel->create($buildingId, $floorId, $name, $capacity);
            return ['success' => true, 'message' => 'Room created successfully', 'id' => $id, 'name' => $name];
        } catch (Exception $e) {
            // TASK 55 — see createBuilding() above: never echo a raw
            // exception message to the client.
            Logger::error('Failed to create room', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to create room', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function updateRoom($data) {
        $id = (int)($data['id'] ?? 0);
        $buildingId = (int)($data['building_id'] ?? 0);
        $floorId = (int)($data['floor_id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        $capacity = $data['capacity'] ?? null;

        if ($id <= 0 || $buildingId <= 0 || $floorId <= 0 || $name === '') {
            return ['success' => false, 'message' => 'Room ID, building ID, floor ID and name are required', 'code' => Response::HTTP_BAD_REQUEST];
        }

        if ($capacity !== null && $capacity !== '') {
            $capacity = (int)$capacity;
            if ($capacity < 1 || $capacity > 60) {
                return ['success' => false, 'message' => 'Room capacity must be between 1 and 60', 'code' => Response::HTTP_BAD_REQUEST];
            }
        }

        try {
            $floor = $this->floorModel->findById($floorId);
            if (!$floor || (int)$floor['building_id'] !== $buildingId) {
                return ['success' => false, 'message' => 'Floor not found or does not belong to building', 'code' => Response::HTTP_BAD_REQUEST];
            }

            if ($this->roomModel->existsByFloorAndName($floorId, $name, $id)) {
                return ['success' => false, 'message' => 'Room with this name already exists on this floor', 'code' => Response::HTTP_CONFLICT];
            }

            // TASK 55 — same building-wide gap as createRoom() above.
            if ($this->roomModel->existsByBuildingAndName($buildingId, $name, $id)) {
                return ['success' => false, 'message' => 'Room with this name already exists in this building', 'code' => Response::HTTP_CONFLICT];
            }

            $this->roomModel->update($id, $buildingId, $floorId, $name, $capacity);
            return ['success' => true, 'message' => 'Room updated successfully'];
        } catch (Exception $e) {
            Logger::error('Failed to update room', ['id' => $id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to update room', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function deleteRoom($id) {
        if (!$id) {
            return ['success' => false, 'message' => 'Room ID is required', 'code' => Response::HTTP_BAD_REQUEST];
        }

        try {
            $this->roomModel->delete($id);
            return ['success' => true, 'message' => 'Room deleted successfully'];
        } catch (Exception $e) {
            Logger::error('Failed to delete room', ['id' => $id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to delete room', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function listItems($itemType = null) {
        try {
            $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
            $perPage = isset($_GET['per_page']) ? max(1, min(200, (int)$_GET['per_page'])) : 20;
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $offset = ($page - 1) * $perPage;

            $res = $this->itemModel->getAllPaginated($itemType, $q, $perPage, $offset);
            $items = $res['items'] ?? [];
            $total = isset($res['total']) ? (int)$res['total'] : count($items);

            return [
                'success' => true,
                'message' => 'Items retrieved successfully',
                'data' => [
                    'items' => $items,
                    'pagination' => [
                        'total' => $total,
                        'per_page' => $perPage,
                        'current_page' => $page,
                        'last_page' => (int)ceil($total / max(1, $perPage))
                    ]
                ]
            ];
        } catch (Exception $e) {
            Logger::error('Failed to list items', ['item_type' => $itemType, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to retrieve items', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function listItemsByRoom($roomId) {
        if (!$roomId) {
            return ['success' => false, 'message' => 'Room ID is required', 'code' => Response::HTTP_BAD_REQUEST];
        }

        try {
            $rows = $this->itemModel->getByRoom($roomId);
            return ['success' => true, 'message' => 'Items retrieved successfully', 'items' => $rows];
        } catch (Exception $e) {
            Logger::error('Failed to list items by room', ['room_id' => $roomId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to retrieve items', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function getItem($id) {
        if (!$id) {
            return ['success' => false, 'message' => 'Item ID is required', 'code' => Response::HTTP_BAD_REQUEST];
        }

        try {
            $row = $this->itemModel->findById($id);
            if (!$row) {
                return ['success' => false, 'message' => 'Item not found', 'code' => Response::HTTP_NOT_FOUND];
            }
            return ['success' => true, 'message' => 'Item retrieved successfully', 'item' => $row];
        } catch (Exception $e) {
            Logger::error('Failed to get item', ['id' => $id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to retrieve item', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function createItem($data) {
        $roomId = (int)($data['room_id'] ?? 0);
        $categoryId = isset($data['category_id']) && (int)$data['category_id'] > 0 ? (int)$data['category_id'] : null;
        $name = trim((string)($data['name'] ?? ''));
        $quantity = (int)($data['quantity'] ?? 1);
        $description = trim((string)($data['description'] ?? ''));
        $thresholdOverride = array_key_exists('low_stock_threshold_override', $data) && $data['low_stock_threshold_override'] !== ''
            ? (int)$data['low_stock_threshold_override']
            : null;

        if ($roomId <= 0 || $name === '' || $quantity <= 0) {
            return ['success' => false, 'message' => 'Room ID, item name and quantity are required', 'code' => Response::HTTP_BAD_REQUEST];
        }

        if ($thresholdOverride !== null && $thresholdOverride < 0) {
            return ['success' => false, 'message' => 'Threshold override must be zero or greater', 'code' => Response::HTTP_BAD_REQUEST];
        }

        try {
            if (!$this->roomModel->findById($roomId)) {
                return ['success' => false, 'message' => 'Room not found', 'code' => Response::HTTP_NOT_FOUND];
            }

            $category = $this->getValidatedCategory($categoryId);
            if ($categoryId !== null && !$category) {
                return ['success' => false, 'message' => 'Category not found', 'code' => Response::HTTP_NOT_FOUND];
            }

            if ($thresholdOverride !== null && $category && (int)$category['allow_threshold_override'] !== 1) {
                return ['success' => false, 'message' => 'This category does not allow threshold override', 'code' => Response::HTTP_BAD_REQUEST];
            }

            $this->pdo->beginTransaction();

            $existingItem = $this->itemModel->findByRoomAndName($roomId, $name);
            $performedBy = isset($_SESSION['user']['user_id']) ? (int)$_SESSION['user']['user_id'] : null;

            if ($existingItem) {
                $itemId = (int)$existingItem['id'];
                $updatedQuantity = (int)$existingItem['quantity'] + $quantity;
                $finalCategoryId = $categoryId ?? (isset($existingItem['category_id']) ? (int)$existingItem['category_id'] : null);
                if ($finalCategoryId !== null && $finalCategoryId <= 0) {
                    $finalCategoryId = null;
                }

                $effectiveCategory = $this->getValidatedCategory($finalCategoryId);
                $finalThresholdOverride = $thresholdOverride;
                if ($finalThresholdOverride === null && array_key_exists('low_stock_threshold_override', $existingItem)) {
                    $current = $existingItem['low_stock_threshold_override'];
                    $finalThresholdOverride = $current === null ? null : (int)$current;
                }

                if ($effectiveCategory && $finalThresholdOverride !== null && (int)$effectiveCategory['allow_threshold_override'] !== 1) {
                    $finalThresholdOverride = null;
                }

                $autoStatus = $this->deriveItemStatusByQuantity(
                    $updatedQuantity,
                    $finalThresholdOverride,
                    $effectiveCategory['default_low_stock_threshold'] ?? null
                );

                $updatedDescription = $description !== '' ? $description : ($existingItem['description'] ?? null);
                $this->itemModel->update(
                    $itemId,
                    $roomId,
                    $finalCategoryId,
                    $existingItem['name'],
                    $autoStatus,
                    $updatedQuantity,
                    $finalThresholdOverride,
                    $updatedDescription
                );
                $message = 'Item stock updated successfully';
            } else {
                $autoStatus = $this->deriveItemStatusByQuantity(
                    $quantity,
                    $thresholdOverride,
                    $category['default_low_stock_threshold'] ?? null
                );

                $itemId = $this->itemModel->create(
                    $roomId,
                    $categoryId,
                    $name,
                    $autoStatus,
                    $quantity,
                    $thresholdOverride,
                    $description
                );
                $message = 'Item created successfully';
            }

            $stmt = $this->pdo->prepare(
                "INSERT INTO inventory_transactions (item_id, report_id, room_id, transaction_type, quantity, reference_note, performed_by, created_at)
                 VALUES (?, NULL, ?, 'adjustment', ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $itemId,
                $roomId,
                $quantity,
                $existingItem ? "Stock intake added to existing item #{$itemId}" : "Stock intake recorded for new item #{$itemId}",
                $performedBy
            ]);

            $this->pdo->commit();

            return ['success' => true, 'message' => $message, 'id' => $itemId, 'name' => $name];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            // TASK 55 — see createBuilding() above: never echo a raw
            // exception message to the client.
            Logger::error('Failed to create item', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to create item', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    private function getValidatedCategory($categoryId) {
        if ($categoryId === null) {
            return null;
        }

        $category = $this->inventoryCategoryModel->findById((int)$categoryId);
        return $category ?: null;
    }

    private function deriveItemStatusByQuantity($quantity, $thresholdOverride = null, $categoryDefaultThreshold = null) {
        $qty = (int)$quantity;
        if ($qty <= 0) {
            return 'out_of_stock';
        }

        $effectiveThreshold = $thresholdOverride !== null ? (int)$thresholdOverride : null;
        if ($effectiveThreshold === null && $categoryDefaultThreshold !== null) {
            $effectiveThreshold = (int)$categoryDefaultThreshold;
        }

        if ($effectiveThreshold !== null && $qty <= $effectiveThreshold) {
            return 'low_stock';
        }

        return 'available';
    }

    public function updateItem($data) {
        $id = (int)($data['id'] ?? 0);
        $roomId = (int)($data['room_id'] ?? 0);
        $categoryId = isset($data['category_id']) && $data['category_id'] !== '' ? (int)$data['category_id'] : null;
        $name = trim((string)($data['name'] ?? ''));
        $quantity = isset($data['quantity']) ? (int)$data['quantity'] : null;
        $description = $data['description'] ?? null;
        $thresholdOverride = array_key_exists('low_stock_threshold_override', $data) && $data['low_stock_threshold_override'] !== ''
            ? (int)$data['low_stock_threshold_override']
            : null;

        if ($id <= 0 || $roomId <= 0 || $name === '') {
            return ['success' => false, 'message' => 'Item ID, room ID and name are required', 'code' => Response::HTTP_BAD_REQUEST];
        }

        if ($thresholdOverride !== null && $thresholdOverride < 0) {
            return ['success' => false, 'message' => 'Threshold override must be zero or greater', 'code' => Response::HTTP_BAD_REQUEST];
        }

        try {
            $existingItem = $this->itemModel->findById($id);
            if (!$existingItem) {
                return ['success' => false, 'message' => 'Item not found', 'code' => Response::HTTP_NOT_FOUND];
            }

            $finalCategoryId = $categoryId;
            if ($finalCategoryId === null && array_key_exists('category_id', $existingItem)) {
                $currentCategoryId = $existingItem['category_id'];
                $finalCategoryId = $currentCategoryId === null ? null : (int)$currentCategoryId;
            }
            if ($finalCategoryId !== null && $finalCategoryId <= 0) {
                $finalCategoryId = null;
            }

            $category = $this->getValidatedCategory($finalCategoryId);
            if ($finalCategoryId !== null && !$category) {
                return ['success' => false, 'message' => 'Category not found', 'code' => Response::HTTP_NOT_FOUND];
            }

            $finalThresholdOverride = $thresholdOverride;
            if ($finalThresholdOverride === null && array_key_exists('low_stock_threshold_override', $existingItem)) {
                $currentOverride = $existingItem['low_stock_threshold_override'];
                $finalThresholdOverride = $currentOverride === null ? null : (int)$currentOverride;
            }

            if ($finalThresholdOverride !== null && $category && (int)$category['allow_threshold_override'] !== 1) {
                $finalThresholdOverride = null;
            }

            $finalQuantity = $quantity ?? (int)$existingItem['quantity'];
            $status = $this->deriveItemStatusByQuantity(
                $finalQuantity,
                $finalThresholdOverride,
                $category['default_low_stock_threshold'] ?? null
            );

            $this->itemModel->update(
                $id,
                $roomId,
                $finalCategoryId,
                $name,
                $status,
                $finalQuantity,
                $finalThresholdOverride,
                $description
            );
            return ['success' => true, 'message' => 'Item updated successfully'];
        } catch (Exception $e) {
            Logger::error('Failed to update item', ['id' => $id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to update item', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function deleteItem($id) {
        if (!$id) {
            return ['success' => false, 'message' => 'Item ID is required', 'code' => Response::HTTP_BAD_REQUEST];
        }

        try {
            $this->itemModel->delete($id);
            return ['success' => true, 'message' => 'Item deleted successfully'];
        } catch (Exception $e) {
            Logger::error('Failed to delete item', ['id' => $id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to delete item', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function listInventoryCategories() {
        try {
            $rows = $this->inventoryCategoryModel->getAll(false);
            return ['success' => true, 'message' => 'Categories retrieved successfully', 'categories' => $rows];
        } catch (Exception $e) {
            Logger::error('Failed to list inventory categories', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to retrieve categories', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function getInventoryCategory($id) {
        if (!$id) {
            return ['success' => false, 'message' => 'Category ID is required', 'code' => Response::HTTP_BAD_REQUEST];
        }

        try {
            $row = $this->inventoryCategoryModel->findById($id);
            if (!$row) {
                return ['success' => false, 'message' => 'Category not found', 'code' => Response::HTTP_NOT_FOUND];
            }
            return ['success' => true, 'message' => 'Category retrieved successfully', 'category' => $row];
        } catch (Exception $e) {
            Logger::error('Failed to get category', ['id' => $id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to retrieve category', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function createInventoryCategory($data) {
        if (!$this->canManageInventoryCategories()) {
            return ['success' => false, 'message' => 'Unauthorized', 'code' => Response::HTTP_FORBIDDEN];
        }

        $name = trim((string)($data['name'] ?? ''));
        $codeRaw = trim((string)($data['code'] ?? ''));
        $code = $codeRaw !== '' ? strtolower($codeRaw) : null;
        $defaultThreshold = array_key_exists('default_low_stock_threshold', $data) && $data['default_low_stock_threshold'] !== ''
            ? (int)$data['default_low_stock_threshold']
            : null;
        $allowOverride = array_key_exists('allow_threshold_override', $data) ? (int)((bool)$data['allow_threshold_override']) : 1;
        $isActive = array_key_exists('is_active', $data) ? (int)((bool)$data['is_active']) : 1;
        $sortOrder = array_key_exists('sort_order', $data) ? (int)$data['sort_order'] : 0;

        if ($name === '') {
            return ['success' => false, 'message' => 'Category name is required', 'code' => Response::HTTP_BAD_REQUEST];
        }

        if ($defaultThreshold !== null && $defaultThreshold < 0) {
            return ['success' => false, 'message' => 'Default threshold must be zero or greater', 'code' => Response::HTTP_BAD_REQUEST];
        }

        try {
            if ($this->inventoryCategoryModel->existsByName($name)) {
                return ['success' => false, 'message' => 'Category with this name already exists', 'code' => Response::HTTP_CONFLICT];
            }

            // TASK 55 — Security & Input Validation Hardening:
            // inventory_categories.code carries its OWN unique index,
            // independent of `name` (see
            // 2026_04_07_001000_add_inventory_categories_and_thresholds.php),
            // and is a real, live field on the Category admin form
            // (categoryCodeInput in public/frontend/pages/inventory.php) —
            // not merely an API-only concern. Only `name` was ever checked
            // for a duplicate here, so two categories with different names
            // but the same code (an easy, realistic mistake — e.g. reusing
            // "consumables") passed validation and then hit an uncaught
            // PDOException on the unique-index violation at INSERT time,
            // whose raw message used to be echoed straight to the client
            // below.
            if ($code !== null && $this->inventoryCategoryModel->existsByCode($code)) {
                return ['success' => false, 'message' => 'Category with this code already exists', 'code' => Response::HTTP_CONFLICT];
            }

            $id = $this->inventoryCategoryModel->create($name, $code, $defaultThreshold, $allowOverride, $isActive, $sortOrder);
            return ['success' => true, 'message' => 'Category created successfully', 'id' => $id];
        } catch (Exception $e) {
            // TASK 55 — see createBuilding() above: never echo a raw
            // exception message to the client.
            Logger::error('Failed to create inventory category', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to create category', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function updateInventoryCategory($data) {
        if (!$this->canManageInventoryCategories()) {
            return ['success' => false, 'message' => 'Unauthorized', 'code' => Response::HTTP_FORBIDDEN];
        }

        $id = (int)($data['id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        $codeRaw = trim((string)($data['code'] ?? ''));
        $code = $codeRaw !== '' ? strtolower($codeRaw) : null;
        $defaultThreshold = array_key_exists('default_low_stock_threshold', $data) && $data['default_low_stock_threshold'] !== ''
            ? (int)$data['default_low_stock_threshold']
            : null;
        $allowOverride = array_key_exists('allow_threshold_override', $data) ? (int)((bool)$data['allow_threshold_override']) : 1;
        $isActive = array_key_exists('is_active', $data) ? (int)((bool)$data['is_active']) : 1;
        $sortOrder = array_key_exists('sort_order', $data) ? (int)$data['sort_order'] : 0;

        if ($id <= 0 || $name === '') {
            return ['success' => false, 'message' => 'Category ID and name are required', 'code' => Response::HTTP_BAD_REQUEST];
        }

        if ($defaultThreshold !== null && $defaultThreshold < 0) {
            return ['success' => false, 'message' => 'Default threshold must be zero or greater', 'code' => Response::HTTP_BAD_REQUEST];
        }

        try {
            $existing = $this->inventoryCategoryModel->findById($id);
            if (!$existing) {
                return ['success' => false, 'message' => 'Category not found', 'code' => Response::HTTP_NOT_FOUND];
            }

            if ($this->inventoryCategoryModel->existsByName($name, $id)) {
                return ['success' => false, 'message' => 'Category with this name already exists', 'code' => Response::HTTP_CONFLICT];
            }

            // TASK 55 — same code-uniqueness gap as createInventoryCategory() above.
            if ($code !== null && $this->inventoryCategoryModel->existsByCode($code, $id)) {
                return ['success' => false, 'message' => 'Category with this code already exists', 'code' => Response::HTTP_CONFLICT];
            }

            $this->inventoryCategoryModel->update($id, $name, $code, $defaultThreshold, $allowOverride, $isActive, $sortOrder);
            return ['success' => true, 'message' => 'Category updated successfully'];
        } catch (Exception $e) {
            Logger::error('Failed to update inventory category', ['id' => $id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to update category', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    public function deleteInventoryCategory($id) {
        if (!$this->canManageInventoryCategories()) {
            return ['success' => false, 'message' => 'Unauthorized', 'code' => Response::HTTP_FORBIDDEN];
        }

        if (!$id) {
            return ['success' => false, 'message' => 'Category ID is required', 'code' => Response::HTTP_BAD_REQUEST];
        }

        try {
            if ($this->inventoryCategoryModel->hasItems($id)) {
                return ['success' => false, 'message' => 'Cannot delete category that has items', 'code' => Response::HTTP_CONFLICT];
            }

            $this->inventoryCategoryModel->delete($id);
            return ['success' => true, 'message' => 'Category deleted successfully'];
        } catch (Exception $e) {
            Logger::error('Failed to delete inventory category', ['id' => $id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to delete category', 'code' => Response::HTTP_INTERNAL_ERROR];
        }
    }

    private function canManageInventoryCategories() {
        $role = strtolower(trim((string)($_SESSION['user']['role'] ?? '')));
        return in_array($role, ['super_admin', 'admin_maintenance', 'maintenance_admin'], true);
    }
}
