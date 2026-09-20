<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * BorrowingAttachmentModel - Model for borrowing_attachments table
 */
class BorrowingAttachmentModel extends Model
{
    protected $table            = 'borrowing_attachments';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'borrowing_request_id',
        'file_name',
        'file_path',
        'file_type',
        'file_size_bytes',
        'attachment_type',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'uploaded_at';
    protected $updatedField  = '';

    protected $validationRules = [
        'borrowing_request_id' => 'required|integer',
        'file_name'            => 'required|max_length[255]',
        'file_path'            => 'required',
        'attachment_type'      => 'required|in_list[usage_photos,event_documents,project_docs,others]',
    ];

    /**
     * Get attachments by borrowing request ID
     */
    public function getByBorrowingRequest(int $borrowingRequestId): array
    {
        return $this->where('borrowing_request_id', $borrowingRequestId)
            ->orderBy('uploaded_at', 'DESC')
            ->findAll();
    }
}