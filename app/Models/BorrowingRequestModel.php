<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * BorrowingRequestModel - Model for borrowing_requests table
 */
class BorrowingRequestModel extends Model
{
    protected $table            = 'borrowing_requests';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'ticket_id',
        'borrower_name',
        'borrower_id_number',
        'borrower_type',
        'department_major',
        'borrower_email',
        'borrower_contact',
        'item_name_requested',
        'item_model_requested',
        'quantity_needed',
        'purpose_project',
        'date_needed',
        'expected_return_date',
        'terms_agreed',
        'terms_agreed_at',
        'status',
        'assigned_inventory_id',
        'assigned_quantity',
        'picked_up_at',
        'picked_up_by',
        'returned_at',
        'returned_by',
        'return_condition',
        'return_notes',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'ticket_id'              => 'required|max_length[60]',
        'borrower_name'          => 'required|max_length[255]',
        'borrower_id_number'     => 'required|max_length[50]',
        'borrower_type'          => 'required|in_list[student,faculty,staff]',
        'borrower_email'         => 'required|valid_email|max_length[255]',
        'borrower_contact'       => 'required|max_length[30]',
        'item_name_requested'    => 'required|max_length[255]',
        'quantity_needed'        => 'required|integer|greater_than_equal_to[1]',
        'purpose_project'        => 'required',
        'date_needed'            => 'required|valid_date',
        'expected_return_date'   => 'required|valid_date',
        'terms_agreed'           => 'required|in_list[0,1]',
    ];

    protected $validationMessages = [
        'terms_agreed' => [
            'in_list' => 'You must agree to the terms and conditions.',
        ],
    ];

    // -------------------------------------------------------------------------
    // Custom Query Methods
    // -------------------------------------------------------------------------

    /**
     * Get borrowing request by ticket ID
     */
    public function getByTicket(string $ticketId): ?array
    {
        return $this->where('ticket_id', $ticketId)->first();
    }

    /**
     * Get borrowing requests queue for LEAU admin dashboard
     */
    public function getQueue(string $status = '', int $limit = 100, int $offset = 0): array
    {
        $builder = $this->builder()
            ->select('borrowing_requests.*, tickets.service_type, tickets.submitted_at, users.first_name, users.last_name, users.email')
            ->join('tickets', 'tickets.id = borrowing_requests.ticket_id')
            ->join('users', 'users.id = tickets.user_id')
            ->where('tickets.unit_id', 2);

        if ($status) {
            $builder->where('borrowing_requests.status', $status);
        }

        $builder->orderBy('borrowing_requests.created_at', 'DESC')
                ->limit($limit, $offset);

        return $builder->get()->getResultArray();
    }

    /**
     * Get overdue borrowing requests
     */
    public function getOverdue(): array
    {
        $today = date('Y-m-d');
        return $this->where('status', 'picked_up')
            ->where('expected_return_date <', $today)
            ->orderBy('expected_return_date', 'ASC')
            ->findAll();
    }

    /**
     * Mark overdue items
     */
    public function markOverdue(): int
    {
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');

        // Find picked_up items past their return date
        $overdueItems = $this->where('status', 'picked_up')
            ->where('expected_return_date <', $today)
            ->findAll();

        $updated = 0;
        foreach ($overdueItems as $item) {
            $this->update($item['id'], [
                'status'      => 'overdue',
                'updated_at'  => $now,
            ]);
            $updated++;
        }

        return $updated;
    }
}