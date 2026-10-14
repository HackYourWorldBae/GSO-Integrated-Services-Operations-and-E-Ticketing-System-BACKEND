<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Models\InventoryItemModel;
use App\Models\TicketLogModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * InventoryController - Handles LEAU inventory management.
 *
 * Mirrors Personnel Management:
 * - CRUD operations for inventory items
 * - Search, filter by category
 * - Status tracking (available, assigned, maintenance, retired)
 */
class InventoryController extends BaseController
{
    private InventoryItemModel $inventoryModel;
    private TicketLogModel $logModel;

    public function __construct()
    {
        $this->inventoryModel = new InventoryItemModel();
        $this->logModel       = new TicketLogModel();
    }

    private function resolveTargetUnitId(?string $unitParam = null): int
    {
        if (!empty($unitParam)) {
            $resolved = $this->resolveUnitId($unitParam);
            if ($resolved !== null) {
                return $resolved;
            }
        }
        $userUnit = $this->currentUserUnitId();
        if ($userUnit !== null && in_array($userUnit, [1, 2], true)) {
            return $userUnit;
        }
        return 2; // Default to LEAU for campus-wide roles when unspecified
    }

    /**
     * Get all inventory items with pagination and filters.
     * GET /api/v1/inventory?unit=&category=&status=&search=&page=&per_page=
     */
    public function index(): ResponseInterface
    {
        $targetUnitId = $this->resolveTargetUnitId($this->request->getGet('unit') ?? $this->request->getGet('unit_id'));
        if ($forbidden = $this->assertUnitAccess($targetUnitId)) {
            return $forbidden;
        }

        $category = sanitize_string($this->request->getGet('category') ?? '');
        $status   = sanitize_string($this->request->getGet('status') ?? '');
        $search   = sanitize_string($this->request->getGet('search') ?? '');
        $page     = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage  = min(500, max(1, (int) ($this->request->getGet('per_page') ?? 50)));

        $items = $this->inventoryModel->getFiltered($category, $status, $search, $perPage, ($page - 1) * $perPage, $targetUnitId);
        $total = $this->inventoryModel->countFiltered($category, $status, $search, $targetUnitId);

        return $this->successResponse('Inventory items retrieved.', [
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'total_pages'=> ceil($total / $perPage),
        ]);
    }

    /**
     * Get a single inventory item.
     * GET /api/v1/inventory/{id}
     */
    public function show(int $id): ResponseInterface
    {
        $item = $this->inventoryModel->find($id);

        if (!$item) {
            return $this->notFoundResponse('Inventory item');
        }

        if ($forbidden = $this->assertUnitAccess((int) $item['unit_id'])) {
            return $forbidden;
        }

        return $this->successResponse('Inventory item retrieved.', ['item' => $item]);
    }

    /**
     * Create a new inventory item.
     * POST /api/v1/inventory
     */
    public function create(): ResponseInterface
    {
        $body = $this->request->getJSON(true) ?? [];
        $targetUnitId = $this->resolveTargetUnitId($body['unit'] ?? $body['unit_id'] ?? null);

        if ($forbidden = $this->assertUnitAccess($targetUnitId)) {
            return $forbidden;
        }

        $role = $this->currentUserRole();
        if (!in_array($role, ['admin', 'superadmin'], true)) {
            $unitName = ($targetUnitId === 1) ? 'FGMU' : 'LEAU';
            return $this->forbiddenResponse("Only {$unitName} Admin can create inventory items.");
        }

        // Validation
        $name = sanitize_string($body['name'] ?? '');
        if (empty($name)) {
            return $this->errorResponse('Item name is required.', ['name' => ['Item name is required.']], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Category restrictions:
        // LEAU (unit 2) is for plants only.
        // FGMU (unit 1) is for tools and equipment (tools, equipment, materials, others).
        $rawCategory = sanitize_string($body['category'] ?? '');
        if ($targetUnitId === 2) {
            $category = 'plants';
        } else {
            $validCategories = ['tools', 'equipment', 'materials', 'others'];
            $category = in_array($rawCategory, $validCategories, true) ? $rawCategory : 'tools';
        }

        $quantityTotal = max(1, (int) ($body['quantity_total'] ?? 1));

        $data = [
            'unit_id'           => $targetUnitId,
            'name'              => $name,
            'model'             => sanitize_string($body['model'] ?? ''),
            'category'          => $category,
            'serial_number'     => sanitize_string($body['serial_number'] ?? ''),
            'quantity_total'    => $quantityTotal,
            'quantity_available'=> $quantityTotal,
            'condition_status'  => sanitize_string($body['condition_status'] ?? 'good'),
            'location'          => sanitize_string($body['location'] ?? ''),
            'description'       => sanitize_string($body['description'] ?? ''),
            'is_active'          => isset($body['is_active']) ? (int) (bool) $body['is_active'] : 1,
            'created_by'        => $this->currentUserId(),
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ];

        $id = $this->inventoryModel->insert($data, true);

        if (!$id) {
            return $this->errorResponse('Failed to create inventory item.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->logModel->logAction('inventory-' . $id, $this->currentUserId(), 'Inventory Created', "Created inventory item: {$name} (Unit: {$targetUnitId}, Category: {$category}, Qty: {$quantityTotal})");

        return $this->successResponse('Inventory item created successfully.', ['item' => $this->inventoryModel->find($id)], ResponseInterface::HTTP_CREATED);
    }

    /**
     * Update an inventory item.
     * PATCH /api/v1/inventory/{id}
     */
    public function update(int $id): ResponseInterface
    {
        $item = $this->inventoryModel->find($id);
        if (!$item) {
            return $this->notFoundResponse('Inventory item');
        }

        if ($forbidden = $this->assertUnitAccess((int) $item['unit_id'])) {
            return $forbidden;
        }

        $role = $this->currentUserRole();
        if (!in_array($role, ['admin', 'superadmin'], true)) {
            return $this->forbiddenResponse('You do not have permission to update inventory items.');
        }

        $body = $this->request->getJSON(true) ?? [];

        $updateData = ['updated_at' => date('Y-m-d H:i:s')];
        $changedFields = [];

        $allowedFields = [
            'name' => 'name',
            'model' => 'model',
            'category' => 'category',
            'serial_number' => 'serial_number',
            'quantity_total' => 'quantity_total',
            'condition_status' => 'condition_status',
            'location' => 'location',
            'description' => 'description',
            'is_active' => 'is_active',
        ];

        foreach ($allowedFields as $inputKey => $dbKey) {
            if (isset($body[$inputKey])) {
                $newValue = $body[$inputKey];
                $oldValue = $item[$dbKey] ?? null;

                // Category rule enforcement
                if ($inputKey === 'category') {
                    $itemUnit = (int) $item['unit_id'];
                    $catVal = sanitize_string($newValue);
                    if ($itemUnit === 2) {
                        $catVal = 'plants';
                    } elseif ($catVal === 'plants') {
                        $catVal = 'tools';
                    }
                    if ($catVal !== $oldValue) {
                        $updateData['category'] = $catVal;
                        $changedFields[] = "category: {$oldValue} -> {$catVal}";
                    }
                    continue;
                }

                // Special handling for quantity_total - adjust available accordingly
                if ($inputKey === 'quantity_total') {
                    $newQty = max(1, (int) $newValue);
                    $diff = $newQty - (int) $oldValue;
                    $updateData['quantity_total'] = $newQty;
                    $updateData['quantity_available'] = max(0, (int) $item['quantity_available'] + $diff);
                    $changedFields[] = "quantity_total: {$oldValue} -> {$newValue} (available adjusted by {$diff})";
                    continue;
                }

                // Special handling for is_active
                if ($inputKey === 'is_active') {
                    $updateData[$dbKey] = (bool) $newValue;
                    $changedFields[] = "{$dbKey}: " . ($oldValue ? 'true' : 'false') . " -> " . ((bool) $newValue ? 'true' : 'false');
                    continue;
                }

                $sanitized = sanitize_string($newValue);
                if ($sanitized !== $oldValue) {
                    $updateData[$dbKey] = $sanitized;
                    $changedFields[] = "{$dbKey}: {$oldValue} -> {$sanitized}";
                }
            }
        }

        if (empty($updateData) || count($updateData) === 1) {
            return $this->successResponse('No changes made.', ['item' => $item]);
        }

        $this->inventoryModel->update($id, $updateData);

        $this->logModel->logAction('inventory-' . $id, $this->currentUserId(), 'Inventory Updated', implode('; ', $changedFields));

        return $this->successResponse('Inventory item updated successfully.', ['item' => $this->inventoryModel->find($id)]);
    }

    /**
     * Delete an inventory item (soft delete by setting is_active = 0).
     * DELETE /api/v1/inventory/{id}
     */
    public function delete(int $id): ResponseInterface
    {
        $item = $this->inventoryModel->find($id);
        if (!$item) {
            return $this->notFoundResponse('Inventory item');
        }

        if ($forbidden = $this->assertUnitAccess((int) $item['unit_id'])) {
            return $forbidden;
        }

        $role = $this->currentUserRole();
        if (!in_array($role, ['admin', 'superadmin'], true)) {
            return $this->forbiddenResponse('You do not have permission to delete inventory items.');
        }

        // Check if item has active borrowings
        $db = Database::connect();
        $activeBorrowings = $db->table('borrowing_requests')
            ->where('assigned_inventory_id', $id)
            ->whereIn('status', ['inventory_assigned', 'ready_for_pickup', 'picked_up', 'overdue'])
            ->countAllResults();

        if ($activeBorrowings > 0) {
            // Soft delete only
            $this->inventoryModel->update($id, [
                'is_active'  => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $this->logModel->logAction('inventory-' . $id, $this->currentUserId(), 'Inventory Deactivated', "Item has active borrowings, set to inactive instead of hard delete.");
            return $this->successResponse('Inventory item has active borrowings. Set to inactive instead.', ['item' => $this->inventoryModel->find($id)]);
        }

        // Hard delete if no active borrowings
        $this->inventoryModel->delete($id);
        $this->logModel->logAction('inventory-' . $id, $this->currentUserId(), 'Inventory Deleted', "Permanently deleted inventory item: {$item['name']}");

        return $this->successResponse('Inventory item deleted successfully.');
    }

    /**
     * Adjust available quantity of an inventory item.
     * PATCH /api/v1/inventory/{id}/adjust-quantity
     */
    public function adjustQuantity(int $id): ResponseInterface
    {
        $item = $this->inventoryModel->find($id);
        if (!$item) {
            return $this->notFoundResponse('Inventory item');
        }

        if ($forbidden = $this->assertUnitAccess((int) $item['unit_id'])) {
            return $forbidden;
        }

        $role = $this->currentUserRole();
        if (!in_array($role, ['admin', 'superadmin'], true)) {
            return $this->forbiddenResponse('You do not have permission to adjust inventory quantity.');
        }

        $body = $this->request->getJSON(true) ?? [];
        $newQuantity = (int) ($body['new_quantity'] ?? 0);

        if ($newQuantity < 0 || $newQuantity > (int) $item['quantity_total']) {
            return $this->errorResponse("Invalid quantity. Must be between 0 and {$item['quantity_total']}.", ['new_quantity' => ['Invalid quantity.']], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $oldAvailable = (int) $item['quantity_available'];
        $this->inventoryModel->update($id, [
            'quantity_available' => $newQuantity,
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        $this->logModel->logAction('inventory-' . $id, $this->currentUserId(), 'Quantity Adjusted', "Available quantity changed from {$oldAvailable} to {$newQuantity} (Total: {$item['quantity_total']})");

        return $this->successResponse('Quantity adjusted successfully.', [
            'item' => $this->inventoryModel->find($id),
        ]);
    }

    /**
     * Get inventory categories for filter dropdown.
     * GET /api/v1/inventory/categories?unit=
     */
    public function categories(): ResponseInterface
    {
        $targetUnitId = $this->resolveTargetUnitId($this->request->getGet('unit') ?? $this->request->getGet('unit_id'));
        if ($forbidden = $this->assertUnitAccess($targetUnitId)) {
            return $forbidden;
        }

        if ($targetUnitId === 2) {
            $categories = [
                ['value' => 'plants', 'label' => 'Plants'],
            ];
        } else {
            $categories = [
                ['value' => 'tools',        'label' => 'Tools'],
                ['value' => 'equipment',    'label' => 'Equipment'],
                ['value' => 'materials',    'label' => 'Materials'],
                ['value' => 'others',       'label' => 'Others'],
            ];
        }

        return $this->successResponse('Inventory categories retrieved.', [
            'categories' => $categories,
        ]);
    }

    /**
     * Get inventory stats.
     * GET /api/v1/inventory/stats?unit=
     */
    public function stats(): ResponseInterface
    {
        $targetUnitId = $this->resolveTargetUnitId($this->request->getGet('unit') ?? $this->request->getGet('unit_id'));
        if ($forbidden = $this->assertUnitAccess($targetUnitId)) {
            return $forbidden;
        }

        $db = Database::connect();

        $total = $db->table('inventory_items')->where('unit_id', $targetUnitId)->where('is_active', 1)->countAllResults();
        $available = $db->table('inventory_items')->where('unit_id', $targetUnitId)->where('is_active', 1)->where('quantity_available >', 0)->countAllResults();
        $lowStock = $db->table('inventory_items')->where('unit_id', $targetUnitId)->where('is_active', 1)->where('quantity_available', 0)->countAllResults();
        $byCategory = $db->table('inventory_items')
            ->select('category, COUNT(*) as count')
            ->where('unit_id', $targetUnitId)
            ->where('is_active', 1)
            ->groupBy('category')
            ->get()->getResultArray();

        return $this->successResponse('Inventory stats retrieved.', [
            'total_items'      => $total,
            'available_items'  => $available,
            'out_of_stock'     => $lowStock,
            'by_category'      => $byCategory,
        ]);
    }
}