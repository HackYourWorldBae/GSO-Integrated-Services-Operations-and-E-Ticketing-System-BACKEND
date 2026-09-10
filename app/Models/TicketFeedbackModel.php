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
     * Get average ratings by unit for the Director dashboard with optional period filtering.
     *
     * @param int|null $unitId  Sub-unit ID (1=FGMU, 2=LEAU, 3=SSU) or null for all units
     * @param array    $filters Optional period filters ['period', 'year', 'quarter', 'month']
     */
    public function getUnitAverageRatings(?int $unitId = null, array $filters = []): array
    {
        $db = \Config\Database::connect();

        $whereConditions = [];
        $params = [];

        if ($unitId !== null) {
            $whereConditions[] = "t.unit_id = ?";
            $params[] = (int)$unitId;
        }

        $period = strtolower((string)($filters['period'] ?? 'all'));
        $year = (int)($filters['year'] ?? date('Y'));
        $quarter = (int)($filters['quarter'] ?? ceil(date('n') / 3));
        $month = (int)($filters['month'] ?? date('n'));

        if ($period === 'year') {
            $whereConditions[] = "YEAR(COALESCE(tf.created_at, t.completed_at, t.submitted_at)) = ?";
            $params[] = $year;
        } elseif ($period === 'quarter') {
            $whereConditions[] = "YEAR(COALESCE(tf.created_at, t.completed_at, t.submitted_at)) = ? AND QUARTER(COALESCE(tf.created_at, t.completed_at, t.submitted_at)) = ?";
            $params[] = $year;
            $params[] = $quarter;
        } elseif ($period === 'month') {
            $whereConditions[] = "YEAR(COALESCE(tf.created_at, t.completed_at, t.submitted_at)) = ? AND MONTH(COALESCE(tf.created_at, t.completed_at, t.submitted_at)) = ?";
            $params[] = $year;
            $params[] = $month;
        }

        $whereSql = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

        $row = $db->query("
            SELECT
                AVG(NULLIF(tf.courtesy_rating, 0))    AS avg_courtesy,
                AVG(NULLIF(tf.quality_rating, 0))     AS avg_quality,
                AVG(NULLIF(tf.efficiency_rating, 0))  AS avg_efficiency,
                AVG(NULLIF(tf.timeliness_rating, 0))  AS avg_timeliness,
                AVG(NULLIF(tf.cleanliness_rating, 0)) AS avg_cleanliness,
                COUNT(tf.id)                          AS total_feedbacks
            FROM ticket_feedbacks tf
            JOIN tickets t ON t.id = tf.ticket_id
            {$whereSql}
        ", $params)->getRowArray() ?? [];

        // Compute overall composite average rating across all non-null dimensions
        $ratings = array_filter([
            $row['avg_courtesy'] ?? null,
            $row['avg_quality'] ?? null,
            $row['avg_efficiency'] ?? null,
            $row['timeliness_rating'] ?? $row['avg_timeliness'] ?? null,
            $row['avg_cleanliness'] ?? null,
        ], fn($v) => $v !== null && is_numeric($v));

        $overall = count($ratings) > 0 ? array_sum($ratings) / count($ratings) : null;
        $row['overall_avg'] = $overall !== null ? round((float)$overall, 2) : 0;

        return $row;
    }
}
