<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * InventoryItemModel - Model for inventory_items table
 */
class InventoryItemModel extends Model
{
    protected $table            = 'inventory_items';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'unit_id',
        'name',
        'model',
        'category',
        'serial_number',
        'quantity_total',
        'quantity_available',
        'condition_status',
        'location',
        'description',
        'is_active',
        'created_by',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'unit_id'            => 'required|integer',
        'name'               => 'required|max_length[255]',
        'category'           => 'required|in_list[tools,equipment,plants,materials,others]',
        'quantity_total'     => 'required|integer|greater_than_equal_to[1]',
        'quantity_available' => 'required|integer|greater_than_equal_to[0]',
        'condition_status'   => 'required|in_list[excellent,good,fair,needs_repair,retired]',
        'is_active'          => 'required|in_list[0,1]',
    ];

    // -------------------------------------------------------------------------
    // Custom Query Methods
    // -------------------------------------------------------------------------

    /**
     * Get filtered inventory items with pagination
     */
    public function getFiltered(string $category = '', string $status = '', string $search = '', int $limit = 50, int $offset = 0): array
    {
        $builder = $this->builder()
            ->where('unit_id', 2)
            ->where('is_active', 1);

        if ($category) {
            $builder->where('category', $category);
        }

        if ($status) {
            switch ($status) {
                case 'available':
                    $builder->where('quantity_available >', 0);
                    break;
                case 'out_of_stock':
                    $builder->where('quantity_available', 0);
                    break;
                case 'maintenance':
                    $builder->where('condition_status', 'needs_repair');
                    break;
                case 'retired':
                    $builder->where('condition_status', 'retired');
                    break;
            }
        }

        if ($search) {
            $builder->groupStart()
                ->like('name', $search)
                ->orLike('model', $search)
                ->orLike('serial_number', $search)
                ->orLike('description', $search)
                ->groupEnd();
        }

        return $builder->orderBy('name', 'ASC')
            ->limit($limit, $offset)
            ->get()->getResultArray();
    }

    /**
     * Count filtered inventory items
     */
    public function countFiltered(string $category = '', string $status = '', string $search = ''): int
    {
        $builder = $this->builder()
            ->where('unit_id', 2)
            ->where('is_active', 1);

        if ($category) {
            $builder->where('category', $category);
        }

        if ($status) {
            switch ($status) {
                case 'available':
                    $builder->where('quantity_available >', 0);
                    break;
                case 'out_of_stock':
                    $builder->where('quantity_available', 0);
                    break;
                case 'maintenance':
                    $builder->where('condition_status', 'needs_repair');
                    break;
                case 'retired':
                    $builder->where('condition_status', 'retired');
                    break;
            }
        }

        if ($search) {
            $builder->groupStart()
                ->like('name', $search)
                ->orLike('model', $search)
                ->orLike('serial_number', $search)
                ->orLike('description', $search)
                ->groupEnd();
        }

        return $builder->countAllResults();
    }
}