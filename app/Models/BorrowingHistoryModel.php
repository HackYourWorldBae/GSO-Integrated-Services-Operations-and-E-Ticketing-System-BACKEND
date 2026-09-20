<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * BorrowingHistoryModel - Model for borrowing_history table
 */
class BorrowingHistoryModel extends Model
{
    protected $table            = 'borrowing_history';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'borrowing_request_id',
        'action',
        'performed_by',
        'details',
        'previous_status',
        'new_status',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = '';

    /**
     * Get history by borrowing request ID
     */
    public function getByBorrowingRequest(int $borrowingRequestId): array
    {
        return $this->where('borrowing_request_id', $borrowingRequestId)
            ->orderBy('created_at', 'DESC')
            ->findAll();
    }
}