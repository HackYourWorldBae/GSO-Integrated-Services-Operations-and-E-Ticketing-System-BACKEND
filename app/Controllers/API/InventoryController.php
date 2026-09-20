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

    /**
     * Get all inventory items with pagination and filters.
     * GET /api/v1/inventory?category=&status=&search=&page=&per_page=
     */
    public function index(): ResponseInterface
    {
        if ($forbidden = $this->assertUnitAccess('LEAU')) {
            return $forbidden;
        }

        $category = sanitize_string($this->request->getGet('category') ?? '');
        $status   = sanitize_string($this->request->getGet('status') ?? '');
        $search   = sanitize_string($this->request->getGet('search') ?? '');
        $page     = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage  = min(500, max(1, (int) ($this->request->getGet('per_page') ?? 50)));

        $items = $this->inventoryModel->getFiltered($category, $status, $search, $perPage, ($page - 1) * $perPage);
        $total = $this->inventoryModel->countFiltered($category, $status, $search);

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
        if ($forbidden = $this->assertUnitAccess('LEAU')) {
            return $forbidden;
        }

        $item = $this->inventoryModel->find($id);

        if (!$item) {
            return $this->notFoundResponse('Inventory item');
        }

        if ((int) $item['unit_id'] !== 2) {
            return $this->forbiddenResponse('Inventory item does not belong to LEAU.');
        }

        return $this->successResponse('Inventory item retrieved.', ['item' => $item]);
    }

    /**
     * Create a new inventory item.
     * POST /api/v1/inventory
     */
    public function create(): ResponseInterface
    {
        if ($forbidden = $this->assertUnitAccess('LEAU')) {
            return $forbidden;
        }

        $role = $this->currentUserRole();
        if (!in_array($role, ['admin', 'superadmin'], true)) {
            return $this->forbiddenResponse('Only LEAU Admin can create inventory items.');
        }

        $body = $this->request->getJSON(true) ?? [];

        // Validation
        $name = sanitize_string($body['name'] ?? '');
        if (empty($name)) {
            return $this->errorResponse('Item name is required.', ['name' => ['Item name is required.']], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $category = sanitize_string($body['category'] ?? 'tools');
        $validCategories = ['tools', 'equipment', 'plants', 'materials', 'others'];
        if (!in_array($category, $validCategories, true)) {
            $category = 'tools';
        }

        $quantityTotal = max(1, (int) ($body['quantity_total'] ?? 1));

        $data = [
            'unit_id'           => 2,
            'name'              => $name,
            'model'             => sanitize_string($body['model'] ?? ''),
            'category'          => $category,
            'serial_number'     => sanitize_string($body['serial_number'] ?? ''),
            'quantity_total'    => $quantityTotal,
            'quantity_available'=> $quantityTotal,
            'condition_status'  => sanitize_string($body['condition_status'] ?? 'good'),
            'location'          => sanitize_string($body['location'] ?? ''),
            'description'       => sanitize_string($body['description'] ?? ''),
            'is_active'         => isset($body['is_active']) ? (bool) $body['is_active'] : true,
            'created_by'        => $this->currentUserId(),
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ];

        $id = $this->inventoryModel->insert($data, true);

        if (!$id) {
            return $this->errorResponse('Failed to create inventory item.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->logModel->logAction('inventory-' . $id, $this->currentUserId(), 'Inventory Created', "Created inventory item: {$name} (Category: {$category}, Qty: {$quantityTotal})");

        return $this->successResponse('Inventory item created successfully.', ['item' => $this->inventoryModel->find($id)], ResponseInterface::HTTP_CREATED);
    }

    /**
     * Update an inventory item.
     * PATCH /api/v1/inventory/{id}
     */
    public function update(int $id): ResponseInterface
    {
        if ($forbidden = $this->assertUnitAccess('LEAU')) {
            return $forbidden;
        }

        $role = $this->currentUserRole();
        if (!in_array($role, ['admin', 'superadmin'], true)) {
            return $this->forbiddenResponse('Only LEAU Admin can update inventory items.');
        }

        $item = $this->inventoryModel->find($id);
        if (!$item) {
            return $this->notFoundResponse('Inventory item');
        }

        if ((int) $item['unit_id'] !== 2) {
            return $this->forbiddenResponse('Inventory item does not belong to LEAU.');
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
        if ($forbidden = $this->assertUnitAccess('LEAU')) {
            return $forbidden;
        }

        $role = $this->currentUserRole();
        if (!in_array($role, ['admin', 'superadmin'], true)) {
            return $this->forbiddenResponse('Only LEAU Admin can delete inventory items.');
        }

        $item = $this->inventoryModel->find($id);
        if (!$item) {
            return $this->notFoundResponse('Inventory item');
        }

        if ((int) $item['unit_id'] !== 2) {
            return $this->forbiddenResponse('Inventory item does not belong to LEAU.');
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
        if ($forbidden = $this->assertUnitAccess('LEAU')) {
            return $forbidden;
        }

        $role = $this->currentUserRole();
        if (!in_array($role, ['admin', 'superadmin'], true)) {
            return $this->forbiddenResponse('Only LEAU Admin can adjust inventory quantity.');
        }

        $item = $this->inventoryModel->find($id);
        if (!$item) {
            return $this->notFoundResponse('Inventory item');
        }

        if ((int) $item['unit_id'] !== 2) {
            return $this->forbiddenResponse('Inventory item does not belong to LEAU.');
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
     * GET /api/v1/inventory/categories
     */
    public function categories(): ResponseInterface
    {
        if ($forbidden = $this->assertUnitAccess('LEAU')) {
            return $forbidden;
        }

        return $this->successResponse('Inventory categories retrieved.', [
            'categories' => [
                ['value' => 'tools',        'label' => 'Tools'],
                ['value' => 'equipment',    'label' => 'Equipment'],
                ['value' => 'plants',       'label' => 'Plants'],
                ['value' => 'materials',    'label' => 'Materials'],
                ['value' => 'others',       'label' => 'Others'],
            ],
        ]);
    }

    /**
     * Get inventory stats.
     * GET /api/v1/inventory/stats
     */
    public function stats(): ResponseInterface
    {
        if ($forbidden = $this->assertUnitAccess('LEAU')) {
            return $forbidden;
        }

        $db = Database::connect();

        $total = $db->table('inventory_items')->where('unit_id', 2)->where('is_active', 1)->countAllResults();
        $available = $db->table('inventory_items')->where('unit_id', 2)->where('is_active', 1)->where('quantity_available >', 0)->countAllResults();
        $lowStock = $db->table('inventory_items')->where('unit_id', 2)->where('is_active', 1)->where('quantity_available', 0)->countAllResults();
        $byCategory = $db->table('inventory_items')
            ->select('category, COUNT(*) as count')
            ->where('unit_id', 2)
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