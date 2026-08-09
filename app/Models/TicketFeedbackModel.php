<?php

namespace App\Models;

use CodeIgniter\Model;

class TicketFeedbackModel extends Model
{
    protected $table         = 'ticket_feedbacks';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'ticket_id',
        'user_id',
        'completion_status',
        'courtesy_rating',
        'quality_rating',
        'efficiency_rating',
        'timeliness_rating',
        'cleanliness_rating',
        'remarks',
        'created_at',
    ];

    public function getByTicket(string $ticketId): ?array
    {
        return $this->where('ticket_id', $ticketId)->first();
    }

    /**
     * Get average ratings by unit for the Director dashboard.
     */
    public function getUnitAverageRatings(int $unitId): array
    {
        $db = \Config\Database::connect();

        return $db->query("
            SELECT
                AVG(NULLIF(tf.courtesy_rating, 0))    AS avg_courtesy,
                AVG(NULLIF(tf.quality_rating, 0))     AS avg_quality,
                AVG(NULLIF(tf.efficiency_rating, 0))  AS avg_efficiency,
                AVG(NULLIF(tf.timeliness_rating, 0))  AS avg_timeliness,
                AVG(NULLIF(tf.cleanliness_rating, 0)) AS avg_cleanliness,
                COUNT(tf.id)                          AS total_feedbacks
            FROM ticket_feedbacks tf
            JOIN tickets t ON t.id = tf.ticket_id
            WHERE t.unit_id = ?
        ", [$unitId])->getRowArray() ?? [];
    }
}
