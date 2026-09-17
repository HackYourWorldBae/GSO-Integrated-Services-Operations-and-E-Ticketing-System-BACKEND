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
        if (!in_array($period, ['all', 'year', 'quarter', 'month', 'day', 'daily'])) {
            $period = 'all';
        }
        if ($period === 'daily') {
            $period = 'day';
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

        $dateRaw = $this->request->getGet('date');
        $targetDate = ($dateRaw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw)) ? $dateRaw : date('Y-m-d');

        $unitFilter = strtoupper((string)($this->request->getGet('unit') ?? 'ALL'));

        $filters = [
            'period'  => $period,
            'year'    => $year,
            'quarter' => $quarter,
            'month'   => $month,
            'date'    => $targetDate,
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

        if ($period === 'day') {
            $isToday = ($targetDate === date('Y-m-d'));
            $periodLabel = $isToday ? "Today (" . date('M j, Y') . ")" : date('F j, Y', strtotime($targetDate));
            $reportTitle = "Daily Operations & Performance Report";
            $reportType = "Daily";
        } elseif ($period === 'month') {
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

        // 3. Service Categories Breakdown for the Period (Overall & Per Sub-Unit)
        $dateConds = [];
        $dateParams = [];
        if ($period === 'year') {
            $dateConds[] = "YEAR(t.submitted_at) = ?";
            $dateParams[] = $year;
        } elseif ($period === 'quarter') {
            $dateConds[] = "YEAR(t.submitted_at) = ? AND QUARTER(t.submitted_at) = ?";
            $dateParams[] = $year;
            $dateParams[] = $quarter;
        } elseif ($period === 'month') {
            $dateConds[] = "YEAR(t.submitted_at) = ? AND MONTH(t.submitted_at) = ?";
            $dateParams[] = $year;
            $dateParams[] = $month;
        } elseif ($period === 'day') {
            $dateConds[] = "DATE(t.submitted_at) = ?";
            $dateParams[] = $targetDate;
        }
        $whereSql = !empty($dateConds) ? "WHERE " . implode(" AND ", $dateConds) : "";

        $serviceRows = $db->query("
            SELECT 
                u.code AS unit_code,
                u.name AS unit_name,
                COALESCE(NULLIF(t.service_type, ''), 'General Maintenance') AS service_type,
                COUNT(*) AS count
            FROM tickets t
            JOIN units u ON u.id = t.unit_id
            {$whereSql}
            GROUP BY t.unit_id, u.code, u.name, t.service_type
            ORDER BY count DESC
        ", $dateParams)->getResultArray();

        $serviceBreakdown = [];
        $serviceBreakdownByUnit = [
            'FGMU' => [
                'unit_code' => 'FGMU',
                'unit_name' => $unitFullNames['FGMU'] ?? 'Facilities & Grounds Management Unit',
                'total'     => 0,
                'services'  => [],
            ],
            'LEAU' => [
                'unit_code' => 'LEAU',
                'unit_name' => $unitFullNames['LEAU'] ?? 'Landscaping & Environmental Aesthetics Unit',
                'total'     => 0,
                'services'  => [],
            ],
            'SSU'  => [
                'unit_code' => 'SSU',
                'unit_name' => $unitFullNames['SSU'] ?? 'Safety & Security Services Unit',
                'total'     => 0,
                'services'  => [],
            ],
        ];

        foreach ($serviceRows as $row) {
            $c = (int)$row['count'];
            $pct = $totalRequestsAcross > 0 ? round(($c / $totalRequestsAcross) * 100, 1) : 0;
            $uCode = strtoupper((string)($row['unit_code'] ?? 'FGMU'));

            $serviceBreakdown[] = [
                'unit_code' => $uCode,
                'unit_name' => $row['unit_name'],
                'name'      => $row['service_type'],
                'count'     => $c,
                'percent'   => $pct,
            ];

            if (isset($serviceBreakdownByUnit[$uCode])) {
                $serviceBreakdownByUnit[$uCode]['total'] += $c;
                $serviceBreakdownByUnit[$uCode]['services'][] = [
                    'unit_code' => $uCode,
                    'name'      => $row['service_type'],
                    'count'     => $c,
                    'percent'   => 0,
                ];
            }
        }

        // Calculate per-unit percent share for each service
        foreach ($serviceBreakdownByUnit as $uCode => &$uData) {
            $uTot = $uData['total'];
            foreach ($uData['services'] as &$sItem) {
                $sItem['percent'] = $uTot > 0 ? round(($sItem['count'] / $uTot) * 100, 1) : 0;
            }
            unset($sItem);
        }
        unset($uData);

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
        } elseif ($period === 'day') {
            $healthConds[] = "DATE(COALESCE(tf.created_at, t.completed_at, t.submitted_at)) = ?";
            $healthParams[] = $targetDate;
        }
        $healthWhereSql = !empty($healthConds) ? "WHERE " . implode(" AND ", $healthConds) : "";

        $completionHealthRows = $db->query("
            SELECT 
                CASE 
                    WHEN tf.completion_status = 'early' THEN 'early'
                    WHEN tf.completion_status = 'on-time' AND t.completed_at IS NOT NULL AND COALESCE(t.extended_completion_date, t.target_completion_date, t.project_target_date) IS NOT NULL AND DATE(t.completed_at) < DATE(COALESCE(t.extended_completion_date, t.target_completion_date, t.project_target_date)) THEN 'early'
                    ELSE tf.completion_status 
                END AS completion_status,
                COUNT(*) AS count
            FROM ticket_feedbacks tf
            JOIN tickets t ON t.id = tf.ticket_id
            {$healthWhereSql}
            GROUP BY 
                CASE 
                    WHEN tf.completion_status = 'early' THEN 'early'
                    WHEN tf.completion_status = 'on-time' AND t.completed_at IS NOT NULL AND COALESCE(t.extended_completion_date, t.target_completion_date, t.project_target_date) IS NOT NULL AND DATE(t.completed_at) < DATE(COALESCE(t.extended_completion_date, t.target_completion_date, t.project_target_date)) THEN 'early'
                    ELSE tf.completion_status 
                END
        ", $healthParams)->getResultArray();

        $earlyFinished = 0;
        $onTime = 0;
        $beyondTime = 0;
        $notCompleted = 0;
        foreach ($completionHealthRows as $r) {
            if ($r['completion_status'] === 'early') $earlyFinished = (int)$r['count'];
            if ($r['completion_status'] === 'on-time') $onTime = (int)$r['count'];
            if ($r['completion_status'] === 'beyond-time') $beyondTime = (int)$r['count'];
            if ($r['completion_status'] === 'not-completed') $notCompleted = (int)$r['count'];
        }
        $healthTotal = $earlyFinished + $onTime + $beyondTime + $notCompleted;

        // 5. Top Delay Reasons
        $delayRows = $db->query("
            SELECT fdr.reason_label, COUNT(*) AS count
            FROM ticket_feedback_delay_items tfdi
            JOIN ticket_feedbacks tf ON tf.id = tfdi.feedback_id
            JOIN tickets t ON t.id = tf.ticket_id
            JOIN feedback_delay_reasons fdr ON fdr.id = tfdi.delay_reason_id
            {$healthWhereSql}
            GROUP BY tfdi.delay_reason_id, fdr.reason_label
            ORDER BY count DESC
            LIMIT 5
        ", $healthParams)->getResultArray();

        $delayReasons = array_map(fn($r) => [
            'reason' => $r['reason_label'],
            'count'  => (int)$r['count']
        ], $delayRows);

        // 5.5. Approval Delay Reasons (executive level)
        $appDelayConds = ["t.approval_delay_reason IS NOT NULL", "TRIM(t.approval_delay_reason) != ''"];
        $appDelayParams = [];
        if ($period === 'year') {
            $appDelayConds[] = "YEAR(COALESCE(t.approval_delayed_at, t.submitted_at)) = ?";
            $appDelayParams[] = $year;
        } elseif ($period === 'quarter') {
            $appDelayConds[] = "YEAR(COALESCE(t.approval_delayed_at, t.submitted_at)) = ? AND QUARTER(COALESCE(t.approval_delayed_at, t.submitted_at)) = ?";
            $appDelayParams[] = $year;
            $appDelayParams[] = $quarter;
        } elseif ($period === 'month') {
            $appDelayConds[] = "YEAR(COALESCE(t.approval_delayed_at, t.submitted_at)) = ? AND MONTH(COALESCE(t.approval_delayed_at, t.submitted_at)) = ?";
            $appDelayParams[] = $year;
            $appDelayParams[] = $month;
        } elseif ($period === 'day') {
            $appDelayConds[] = "DATE(COALESCE(t.approval_delayed_at, t.submitted_at)) = ?";
            $appDelayParams[] = $targetDate;
        }
        if ($unitFilter !== 'ALL' && isset(self::UNIT_MAP[$unitFilter])) {
            $appDelayConds[] = "t.unit_id = ?";
            $appDelayParams[] = self::UNIT_MAP[$unitFilter];
        }
        $appDelayWhereSql = "WHERE " . implode(" AND ", $appDelayConds);

        $appDelayRows = $db->query("
            SELECT TRIM(t.approval_delay_reason) AS reason, COUNT(*) AS count
            FROM tickets t
            {$appDelayWhereSql}
            GROUP BY TRIM(t.approval_delay_reason)
            ORDER BY count DESC
            LIMIT 10
        ", $appDelayParams)->getResultArray();

        $approvalDelayReasons = array_map(fn($r) => [
            'reason' => $r['reason'],
            'count'  => (int)$r['count']
        ], $appDelayRows);

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
            GROUP BY t.unit_id, u.code, MONTH(t.submitted_at)
            ORDER BY t.unit_id, MONTH(t.submitted_at)
        ", [$trendYear])->getResultArray();

        // 7. Materials Consumption & Cost Summary
        $matConds = [];
        $matParams = [];

        if ($period === 'year') {
            $matConds[] = "YEAR(COALESCE(tm.created_at, t.completed_at, t.submitted_at)) = ?";
            $matParams[] = $year;
        } elseif ($period === 'quarter') {
            $matConds[] = "YEAR(COALESCE(tm.created_at, t.completed_at, t.submitted_at)) = ? AND QUARTER(COALESCE(tm.created_at, t.completed_at, t.submitted_at)) = ?";
            $matParams[] = $year;
            $matParams[] = $quarter;
        } elseif ($period === 'month') {
            $matConds[] = "YEAR(COALESCE(tm.created_at, t.completed_at, t.submitted_at)) = ? AND MONTH(COALESCE(tm.created_at, t.completed_at, t.submitted_at)) = ?";
            $matParams[] = $year;
            $matParams[] = $month;
        } elseif ($period === 'day') {
            $matConds[] = "DATE(COALESCE(tm.created_at, t.completed_at, t.submitted_at)) = ?";
            $matParams[] = $targetDate;
        }

        if ($unitFilter !== 'ALL' && isset(self::UNIT_MAP[$unitFilter])) {
            $matConds[] = "t.unit_id = ?";
            $matParams[] = self::UNIT_MAP[$unitFilter];
        }

        $matWhereSql = !empty($matConds) ? "WHERE " . implode(" AND ", $matConds) : "";

        $materialRows = $db->query("
            SELECT
                tm.id,
                tm.material_name,
                tm.quantity,
                COALESCE(NULLIF(TRIM(tm.unit_measurement), ''), 'pcs') AS unit_measurement,
                tm.unit_price,
                CASE 
                    WHEN tm.total_price > 0 THEN tm.total_price 
                    ELSE (tm.quantity * tm.unit_price) 
                END AS total_price,
                tm.created_at,
                COALESCE(tm.ticket_id, ta.ticket_id) AS ticket_id,
                t.title AS ticket_title,
                t.service_type,
                t.unit_id,
                u.code AS unit_code,
                u.name AS unit_name
            FROM ticket_materials tm
            LEFT JOIN ticket_assignments ta ON ta.id = tm.assignment_id
            JOIN tickets t ON t.id = COALESCE(tm.ticket_id, ta.ticket_id)
            JOIN units u ON u.id = t.unit_id
            {$matWhereSql}
            ORDER BY tm.created_at DESC, tm.id DESC
        ", $matParams)->getResultArray();

        $totalMaterialsWorth = 0.0;
        $totalMaterialsQuantity = 0.0;
        $unitMaterialsWorth = [
            'FGMU' => 0.0,
            'LEAU' => 0.0,
            'SSU'  => 0.0,
        ];
        $unitMaterialsCount = [
            'FGMU' => 0,
            'LEAU' => 0,
            'SSU'  => 0,
        ];
        $materialsItems = [];

        foreach ($materialRows as $mRow) {
            $qty   = (float) ($mRow['quantity'] ?? 1);
            $price = (float) ($mRow['unit_price'] ?? 0);
            $tot   = (float) ($mRow['total_price'] ?? ($qty * $price));
            $uCode = strtoupper((string) ($mRow['unit_code'] ?? 'FGMU'));

            $totalMaterialsWorth += $tot;
            $totalMaterialsQuantity += $qty;

            if (isset($unitMaterialsWorth[$uCode])) {
                $unitMaterialsWorth[$uCode] += $tot;
                $unitMaterialsCount[$uCode]++;
            }

            $materialsItems[] = [
                'id'               => (int) $mRow['id'],
                'material_name'    => $mRow['material_name'],
                'quantity'         => $qty,
                'unit_measurement' => $mRow['unit_measurement'],
                'unit_price'       => $price,
                'total_price'      => $tot,
                'ticket_id'        => $mRow['ticket_id'],
                'ticket_title'     => $mRow['service_type'] ?: ($mRow['ticket_title'] ?: 'General Maintenance'),
                'service_type'     => $mRow['service_type'] ?: ($mRow['ticket_title'] ?: 'General Maintenance'),
                'job_particulars'  => $mRow['ticket_title'] ?: '',
                'unit_code'        => $uCode,
                'created_at'       => $mRow['created_at'],
            ];
        }

        $materialsSummary = [
            'total_worth'      => round($totalMaterialsWorth, 2),
            'total_quantity'   => round($totalMaterialsQuantity, 2),
            'total_records'    => count($materialsItems),
            'by_unit'          => [
                'FGMU' => [
                    'total_worth' => round($unitMaterialsWorth['FGMU'], 2),
                    'count'       => $unitMaterialsCount['FGMU'],
                ],
                'LEAU' => [
                    'total_worth' => round($unitMaterialsWorth['LEAU'], 2),
                    'count'       => $unitMaterialsCount['LEAU'],
                ],
                'SSU'  => [
                    'total_worth' => round($unitMaterialsWorth['SSU'], 2),
                    'count'       => $unitMaterialsCount['SSU'],
                ],
            ],
            'items'            => $materialsItems,
        ];

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
                'date'         => $targetDate,
                'unit'         => $unitFilter,
                'label'        => $periodLabel,
                'report_title' => $reportTitle,
                'report_type'  => $reportType,
            ],
            'available_years' => $availableYears,
            'summary' => [
                'total_requests'        => $totalRequestsAcross,
                'total_resolved'        => $totalResolvedAcross,
                'total_declined'        => $totalDeclinedAcross,
                'total_pending'         => $totalPendingAcross,
                'total_processing'      => $totalProcessingAcross,
                'total_scheduled'       => $totalScheduledAcross,
                'total_active_working'  => $totalActiveWorkingAcross,
                'completion_rate'       => min(100, $overallCompletionRate),
                'total_materials_worth' => round($totalMaterialsWorth, 2),
                'overall_ratings'       => $overallRatings,
            ],
            'units'                     => $units,
            'service_breakdown'         => $serviceBreakdown,
            'service_breakdown_by_unit' => $serviceBreakdownByUnit,
            'materials_summary'   => $materialsSummary,
            'completion_health'   => [
                'early_finished'        => $earlyFinished,
                'on_time'               => $onTime,
                'beyond_time'           => $beyondTime,
                'not_completed'         => $notCompleted,
                'total'                 => $healthTotal,
                'early_finished_percent'=> $healthTotal > 0 ? round(($earlyFinished / $healthTotal) * 100) : 0,
                'on_time_percent'       => $healthTotal > 0 ? round(($onTime / $healthTotal) * 100) : 0,
                'beyond_time_percent'   => $healthTotal > 0 ? round(($beyondTime / $healthTotal) * 100) : 0,
                'not_completed_percent' => $healthTotal > 0 ? round(($notCompleted / $healthTotal) * 100) : 0,
            ],
            'delay_reasons'           => $delayReasons,
            'approval_delay_reasons'  => $approvalDelayReasons,
            'trends'                  => $trend,
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
            GROUP BY tfdi.delay_reason_id, fdr.reason_label
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
