<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * VehicleModel
 *
 * Manages the TASU fleet of university vehicles.
 */
class VehicleModel extends Model
{
    protected $table         = 'vehicles';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $allowedFields = [
        'unit_id',
        'plate_no',
        'model_name',
        'model_year',
        'fuel_type',
        'engine_specs',
        'category',
        'status',
        'image_url',
        'registered_owner',
    ];

    protected $validationRules = [
        'plate_no'    => 'required|max_length[30]|is_unique[vehicles.plate_no,id,{id}]',
        'model_name'  => 'required|max_length[150]',
        'category'    => 'required|in_list[Van,Pickup,Bus,SUV,Logistics,Sedan,Other]',
    ];

    /**
     * Synchronize any vehicle currently marked 'in_use' that does not have an active,
     * uncompleted ticket assignment (e.g. ticket was already completed, archived, or resolved).
     */
    public function syncStaleVehicleStatuses(): void
    {
        $db = \Config\Database::connect();
        $db->query("
            UPDATE vehicles v
            SET v.status = 'available', v.updated_at = NOW()
            WHERE v.status = 'in_use'
              AND v.id NOT IN (
                  SELECT DISTINCT ta.vehicle_id
                  FROM ticket_assignments ta
                  JOIN tickets t ON t.id = ta.ticket_id
                  WHERE ta.vehicle_id IS NOT NULL
                    AND ta.completed_at IS NULL
                    AND t.status IN ('processing', 'approved', 'pending')
                    AND t.is_archived = 0
              )
        ");
    }

    /**
     * Get all available vehicles (for requestors browsing vehicle availability).
     */
    public function getAvailable(): array
    {
        $this->syncStaleVehicleStatuses();

        return $this->where('status', 'available')
                    ->orderBy('category', 'ASC')
                    ->findAll();
    }

    /**
     * Get all TASU fleet vehicles for the management view (admin).
     */
    public function getTasuFleet(int $tasuUnitId): array
    {
        $this->syncStaleVehicleStatuses();

        return $this->where('unit_id', $tasuUnitId)
                    ->orderBy('category', 'ASC')
                    ->findAll();
    }

    /**
     * Get vehicles with their active booking for the dispatch board calendar.
     */
    public function getDispatchBoardData(int $tasuUnitId): array
    {
        $db = \Config\Database::connect();

        return $db->query("
            SELECT
                v.id                         AS vehicle_id,
                v.plate_no,
                v.model_name,
                v.category,
                v.status                     AS vehicle_status,
                v.image_url,
                ta.id                        AS assignment_id,
                ta.ticket_id,
                ta.implementation_date,
                ta.task_notes,
                ta.dispatcher_notes,
                t.status                     AS booking_status,
                t.status_label,
                tbd.destination,
                tbd.date_of_travel,
                tbd.request_time,
                tbd.return_time,
                tbd.requesting_personnel,
                tbd.office_college_department,
                tbd.num_passengers,
                tbd.purpose_of_travel,
                p.name                       AS assigned_driver,
                p.id                         AS assigned_driver_id
            FROM vehicles v
            LEFT JOIN ticket_assignments ta
                ON ta.vehicle_id = v.id
            LEFT JOIN tickets t
                ON t.id = ta.ticket_id AND t.status != 'declined'
            LEFT JOIN tasu_booking_details tbd
                ON tbd.ticket_id = ta.ticket_id
            LEFT JOIN personnel p
                ON p.id = ta.personnel_id
            WHERE v.unit_id = ?
            ORDER BY v.category ASC, v.model_name ASC
        ", [$tasuUnitId])->getResultArray();
    }
}
