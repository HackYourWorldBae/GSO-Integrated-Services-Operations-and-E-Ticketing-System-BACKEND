<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * PersonnelCategoryModel
 *
 * Manages admin-defined specialty/profession categories per unit.
 * Categories are referenced as the `specialty` value when adding personnel.
 */
class PersonnelCategoryModel extends Model
{
    protected $table            = 'personnel_categories';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;
    protected $allowedFields    = [
        'unit_id',
        'name',
        'is_system',
    ];

    /**
     * Get all categories for a given unit, ordered alphabetically.
     */
    public function getByUnit(int $unitId): array
    {
        return $this->where('unit_id', $unitId)
                    ->orderBy('name', 'ASC')
                    ->findAll();
    }

    /**
     * Check if any personnel are currently using this category name within a unit.
     */
    public function isInUse(int $categoryId): bool
    {
        $category = $this->find($categoryId);
        if (!$category) {
            return false;
        }

        $db    = \Config\Database::connect();
        $count = $db->table('personnel')
                    ->where('unit_id', $category['unit_id'])
                    ->where('specialty', $category['name'])
                    ->countAllResults();

        return $count > 0;
    }
}
