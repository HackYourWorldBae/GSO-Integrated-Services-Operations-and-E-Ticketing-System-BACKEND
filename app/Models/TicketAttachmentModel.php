<?php

namespace App\Models;

use CodeIgniter\Model;

class TicketAttachmentModel extends Model
{
    protected $table         = 'ticket_attachments';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'ticket_id',
        'file_name',
        'file_path',
        'file_type',
        'file_size_bytes',
        'uploaded_at',
    ];

    public function getByTicket(string $ticketId): array
    {
        return $this->where('ticket_id', $ticketId)->findAll();
    }
}
