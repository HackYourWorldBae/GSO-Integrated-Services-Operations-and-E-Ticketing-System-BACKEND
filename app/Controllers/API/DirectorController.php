<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Models\TicketModel;
use App\Models\TicketFeedbackModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * DirectorController
 *
 * Executive dashboard with aggregated analytics for all sub-units.
 *
 * Endpoints:
 *  GET /api/v1/director/analytics          - System-wide KPI summary
 *  GET /api/v1/director/analytics/:unit    - Per-unit analytics
 */
class DirectorController extends BaseController
{
    private TicketModel $ticketModel;
    private TicketFeedbackModel $feedbackModel;

    private const UNIT_MAP = ['FGMU' => 1, 'LEAU' => 2, 'SSU' => 3];

    public function __construct()
    {
        $this->ticketModel   = new TicketModel();
        $this->feedbackModel = new TicketFeedbackModel();
    }

    /**
     * Get system-wide analytics for all units.
     * Returns ticket volumes, completion rates, and feedback averages per unit.
     */
    public function analytics(): ResponseInterface
    {
        $db     = Database::connect();
        $result = [];

        foreach (self::UNIT_MAP as $code => $id) {
            $stats    = $this->ticketModel->getStatsByUnit($id);
            $ratings  = $this->feedbackModel->getUnitAverageRatings($id);

            // Completion rate: resolved / (total - declined - cancelled) * 100
            $total     = (int) ($stats['total'] ?? 0);
            $resolved  = (int) ($stats['resolved'] ?? 0);
            $declined  = (int) ($stats['declined'] ?? 0);
            $eligible  = max(1, $total - $declined);
            $compRate  = round(($resolved / $eligible) * 100, 1);

            $result[$code] = [
                'unit'             => $code,
                'stats'            => $stats,
                'completion_rate'  => $compRate,
                'avg_ratings'      => $ratings,
            ];
        }

        // Monthly trend: total tickets submitted per unit per month for the current year
        $year  = date('Y');
        $trend = $db->query("
            SELECT
                u.code AS unit_code,
                MONTH(t.submitted_at) AS month,
                COUNT(*) AS ticket_count
            FROM tickets t
            JOIN units u ON u.id = t.unit_id
            WHERE YEAR(t.submitted_at) = ?
            GROUP BY t.unit_id, MONTH(t.submitted_at)
            ORDER BY t.unit_id, MONTH(t.submitted_at)
        ", [$year])->getResultArray();

        return $this->successResponse('Executive analytics retrieved.', [
            'year'   => $year,
            'units'  => $result,
            'trends' => $trend,
        ]);
    }

    /**
     * Get per-unit analytics breakdown.
     */
    public function unitAnalytics(string $unitCode): ResponseInterface
    {
        $unitId = self::UNIT_MAP[strtoupper($unitCode)] ?? null;
        if (!$unitId) {
            return $this->errorResponse("Unknown unit code: {$unitCode}.");
        }

        $stats   = $this->ticketModel->getStatsByUnit($unitId);
        $ratings = $this->feedbackModel->getUnitAverageRatings($unitId);

        // Top delay reasons for this unit
        $db = Database::connect();
        $delayReasons = $db->query("
            SELECT fdr.reason_label, COUNT(*) AS count
            FROM ticket_feedback_delay_items tfdi
            JOIN ticket_feedbacks tf ON tf.id = tfdi.feedback_id
            JOIN tickets t ON t.id = tf.ticket_id
            JOIN feedback_delay_reasons fdr ON fdr.id = tfdi.delay_reason_id
            WHERE t.unit_id = ?
            GROUP BY tfdi.delay_reason_id
            ORDER BY count DESC
            LIMIT 5
        ", [$unitId])->getResultArray();

        return $this->successResponse("Unit analytics for {$unitCode}.", [
            'unit'          => strtoupper($unitCode),
            'stats'         => $stats,
            'avg_ratings'   => $ratings,
            'top_delays'    => $delayReasons,
        ]);
    }
}
