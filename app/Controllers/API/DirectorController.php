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
     * Get system-wide analytics for all units with periodic drilldown (monthly, quarterly, annual, all-time).
     * Returns consolidated KPIs, unit performance comparison matrix, service distributions, and feedback ratings.
     */
    public function analytics(): ResponseInterface
    {
        $db = Database::connect();

        // 1. Period & Timeframe Filters
        $period = strtolower((string)($this->request->getGet('period') ?? 'all'));
        if (!in_array($period, ['all', 'year', 'quarter', 'month'])) {
            $period = 'all';
        }

        $currentYear = (int)date('Y');
        $currentMonth = (int)date('n');
        $currentQuarter = (int)ceil($currentMonth / 3);

        $yearRaw = $this->request->getGet('year');
        $year = ($yearRaw !== null && is_numeric($yearRaw)) ? (int)$yearRaw : $currentYear;

        $quarterRaw = $this->request->getGet('quarter');
        $quarter = ($quarterRaw !== null && is_numeric($quarterRaw)) ? (int)$quarterRaw : $currentQuarter;

        $monthRaw = $this->request->getGet('month');
        $month = ($monthRaw !== null && is_numeric($monthRaw)) ? (int)$monthRaw : $currentMonth;

        $unitFilter = strtoupper((string)($this->request->getGet('unit') ?? 'ALL'));

        $filters = [
            'period'  => $period,
            'year'    => $year,
            'quarter' => $quarter,
            'month'   => $month,
        ];

        // Format human-friendly period label and document report title
        $monthNames = [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
        ];
        $quarterLabels = [
            1 => 'Q1 (Jan - Mar)', 2 => 'Q2 (Apr - Jun)',
            3 => 'Q3 (Jul - Sep)', 4 => 'Q4 (Oct - Dec)'
        ];

        if ($period === 'month') {
            $periodLabel = ($monthNames[$month] ?? "Month {$month}") . " {$year}";
            $reportTitle = "Monthly Performance & Operations Report";
            $reportType = "Monthly";
        } elseif ($period === 'quarter') {
            $periodLabel = ($quarterLabels[$quarter] ?? "Q{$quarter}") . " {$year}";
            $reportTitle = "Quarterly Performance & Operations Report";
            $reportType = "Quarterly";
        } elseif ($period === 'year') {
            $periodLabel = "Calendar Year {$year}";
            $reportTitle = "Annual Performance & Operations Report";
            $reportType = "Annual";
        } else {
            $periodLabel = "All-Time Historical Operations";
            $reportTitle = "Comprehensive Operations & Performance Report";
            $reportType = "All-Time";
        }

        // Available historical years from tickets table
        $yearsResult = $db->query("SELECT DISTINCT YEAR(submitted_at) AS yr FROM tickets WHERE submitted_at IS NOT NULL ORDER BY yr DESC")->getResultArray();
        $availableYears = array_values(array_filter(array_map(fn($r) => (int)$r['yr'], $yearsResult)));
        if (!in_array($currentYear, $availableYears)) {
            array_unshift($availableYears, $currentYear);
        }

        // Unit names map
        $unitFullNames = [
            'FGMU' => 'Facilities & Grounds Management Unit',
            'LEAU' => 'Landscaping & Environmental Aesthetics Unit',
            'SSU'  => 'Safety & Security Services Unit'
        ];

        // 2. Compute Per-Unit Statistics
        $units = [];
        $totalRequestsAcross = 0;
        $totalResolvedAcross = 0;
        $totalDeclinedAcross = 0;
        $totalPendingAcross  = 0;
        $totalProcessingAcross = 0;
        $totalScheduledAcross = 0;
        $totalActiveWorkingAcross = 0;

        foreach (self::UNIT_MAP as $code => $id) {
            $stats   = $this->ticketModel->getStatsByUnit($id, $filters);
            $ratings = $this->feedbackModel->getUnitAverageRatings($id, $filters);

            $uTotal    = (int)($stats['total'] ?? 0);
            $uResolved = (int)($stats['resolved'] ?? 0);
            $uDeclined = (int)($stats['declined'] ?? 0);
            $uPending  = (int)($stats['pending'] ?? 0);
            $uProcessing = (int)($stats['processing'] ?? 0);
            $uScheduled = (int)($stats['scheduled'] ?? 0);
            $uActiveWorking = (int)($stats['active_working'] ?? 0);

            $eligible = max(1, $uTotal - $uDeclined);
            $compRate = $uTotal > 0 ? round(($uResolved / $eligible) * 100, 1) : 0;

            $units[$code] = [
                'unit'             => $code,
                'name'             => $unitFullNames[$code] ?? $code,
                'stats'            => $stats,
                'completion_rate'  => min(100, $compRate),
                'avg_ratings'      => $ratings,
                'total'            => $uTotal,
                'resolved'         => $uResolved,
                'declined'         => $uDeclined,
                'pending'          => $uPending,
                'processing'       => $uProcessing,
                'scheduled'        => $uScheduled,
                'active_working'   => $uActiveWorking,
            ];

            $totalRequestsAcross += $uTotal;
            $totalResolvedAcross += $uResolved;
            $totalDeclinedAcross += $uDeclined;
            $totalPendingAcross  += $uPending;
            $totalProcessingAcross += $uProcessing;
            $totalScheduledAcross += $uScheduled;
            $totalActiveWorkingAcross += $uActiveWorking;
        }

        // Overall completion rate
        $overallEligible = max(1, $totalRequestsAcross - $totalDeclinedAcross);
        $overallCompletionRate = $totalRequestsAcross > 0 ? round(($totalResolvedAcross / $overallEligible) * 100, 1) : 0;

        // Overall campus-wide feedback ratings for the period
        $overallRatings = $this->feedbackModel->getUnitAverageRatings(null, $filters);

        // 3. Service Categories Breakdown for the Period
        $dateConds = [];
        $dateParams = [];
        if ($period === 'year') {
            $dateConds[] = "YEAR(submitted_at) = ?";
            $dateParams[] = $year;
        } elseif ($period === 'quarter') {
            $dateConds[] = "YEAR(submitted_at) = ? AND QUARTER(submitted_at) = ?";
            $dateParams[] = $year;
            $dateParams[] = $quarter;
        } elseif ($period === 'month') {
            $dateConds[] = "YEAR(submitted_at) = ? AND MONTH(submitted_at) = ?";
            $dateParams[] = $year;
            $dateParams[] = $month;
        }
        $whereSql = !empty($dateConds) ? "WHERE " . implode(" AND ", $dateConds) : "";

        $serviceRows = $db->query("
            SELECT 
                COALESCE(NULLIF(service_type, ''), 'General Maintenance') AS service_type,
                COUNT(*) AS count
            FROM tickets
            {$whereSql}
            GROUP BY service_type
            ORDER BY count DESC
            LIMIT 8
        ", $dateParams)->getResultArray();

        $serviceBreakdown = [];
        foreach ($serviceRows as $row) {
            $c = (int)$row['count'];
            $pct = $totalRequestsAcross > 0 ? round(($c / $totalRequestsAcross) * 100, 1) : 0;
            $serviceBreakdown[] = [
                'name'    => $row['service_type'],
                'count'   => $c,
                'percent' => $pct
            ];
        }

        // 4. Completion SLA Health for the Period
        $healthConds = [];
        $healthParams = [];
        if ($period === 'year') {
            $healthConds[] = "YEAR(COALESCE(tf.created_at, t.completed_at, t.submitted_at)) = ?";
            $healthParams[] = $year;
        } elseif ($period === 'quarter') {
            $healthConds[] = "YEAR(COALESCE(tf.created_at, t.completed_at, t.submitted_at)) = ? AND QUARTER(COALESCE(tf.created_at, t.completed_at, t.submitted_at)) = ?";
            $healthParams[] = $year;
            $healthParams[] = $quarter;
        } elseif ($period === 'month') {
            $healthConds[] = "YEAR(COALESCE(tf.created_at, t.completed_at, t.submitted_at)) = ? AND MONTH(COALESCE(tf.created_at, t.completed_at, t.submitted_at)) = ?";
            $healthParams[] = $year;
            $healthParams[] = $month;
        }
        $healthWhereSql = !empty($healthConds) ? "WHERE " . implode(" AND ", $healthConds) : "";

        $completionHealthRows = $db->query("
            SELECT tf.completion_status, COUNT(*) AS count
            FROM ticket_feedbacks tf
            JOIN tickets t ON t.id = tf.ticket_id
            {$healthWhereSql}
            GROUP BY tf.completion_status
        ", $healthParams)->getResultArray();

        $onTime = 0;
        $beyondTime = 0;
        $notCompleted = 0;
        foreach ($completionHealthRows as $r) {
            if ($r['completion_status'] === 'on-time') $onTime = (int)$r['count'];
            if ($r['completion_status'] === 'beyond-time') $beyondTime = (int)$r['count'];
            if ($r['completion_status'] === 'not-completed') $notCompleted = (int)$r['count'];
        }
        $healthTotal = $onTime + $beyondTime + $notCompleted;

        // 5. Top Delay Reasons
        $delayRows = $db->query("
            SELECT fdr.reason_label, COUNT(*) AS count
            FROM ticket_feedback_delay_items tfdi
            JOIN ticket_feedbacks tf ON tf.id = tfdi.feedback_id
            JOIN tickets t ON t.id = tf.ticket_id
            JOIN feedback_delay_reasons fdr ON fdr.id = tfdi.delay_reason_id
            {$healthWhereSql}
            GROUP BY tfdi.delay_reason_id
            ORDER BY count DESC
            LIMIT 5
        ", $healthParams)->getResultArray();

        $delayReasons = array_map(fn($r) => [
            'reason' => $r['reason_label'],
            'count'  => (int)$r['count']
        ], $delayRows);

        // 6. Annual Trend (tickets per unit per month for the selected year)
        $trendYear = $year ?: $currentYear;
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
        ", [$trendYear])->getResultArray();

        // Current user / director info
        $currentUser = $this->currentUser();
        $directorName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
        if (empty($directorName)) {
            $directorName = "Office of the Director, General Services Office";
        }

        return $this->successResponse('Executive analytics retrieved.', [
            'filter' => [
                'period'       => $period,
                'year'         => $year,
                'quarter'      => $quarter,
                'month'        => $month,
                'unit'         => $unitFilter,
                'label'        => $periodLabel,
                'report_title' => $reportTitle,
                'report_type'  => $reportType,
            ],
            'available_years' => $availableYears,
            'summary' => [
                'total_requests'     => $totalRequestsAcross,
                'total_resolved'     => $totalResolvedAcross,
                'total_declined'     => $totalDeclinedAcross,
                'total_pending'      => $totalPendingAcross,
                'total_processing'   => $totalProcessingAcross,
                'total_scheduled'    => $totalScheduledAcross,
                'total_active_working' => $totalActiveWorkingAcross,
                'completion_rate'    => min(100, $overallCompletionRate),
                'overall_ratings'    => $overallRatings,
            ],
            'units'               => $units,
            'service_breakdown'   => $serviceBreakdown,
            'completion_health'   => [
                'on_time'            => $onTime,
                'beyond_time'        => $beyondTime,
                'not_completed'      => $notCompleted,
                'total'              => $healthTotal,
                'on_time_percent'    => $healthTotal > 0 ? round(($onTime / $healthTotal) * 100) : 0,
                'beyond_time_percent'=> $healthTotal > 0 ? round(($beyondTime / $healthTotal) * 100) : 0,
                'not_completed_percent'=> $healthTotal > 0 ? round(($notCompleted / $healthTotal) * 100) : 0,
            ],
            'delay_reasons'       => $delayReasons,
            'trends'              => $trend,
            'director_name'       => $directorName,
            'generated_at'        => date('F j, Y, g:i A'),
            'generated_iso'       => date('c'),
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
