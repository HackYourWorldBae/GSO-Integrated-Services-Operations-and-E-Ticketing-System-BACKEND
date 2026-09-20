<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * TicketModel
 *
 * Core ticket entity. All other unit-specific detail tables
 * reference this via ticket_id (FK).
 */
class TicketModel extends Model
{
    protected $table            = 'tickets';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false; // Formatted ticket IDs: FGMU-TIC-42-2026
    protected $returnType       = 'array';
    protected $useTimestamps    = false; // Managed manually for reviewed_at, completed_at
    protected $allowedFields    = [
        'id',
        'user_id',
        'unit_id',
        'title',
        'service_type',
        'description',
        'status',
        'status_label',
        'is_approval_delayed',
        'approval_delay_reason',
        'approval_delayed_at',
        'approval_delayed_by',
        'is_emergency',
        'decline_reason',
        'current_step',
        'location',
        'office_room',
        'is_archived',
        'materials_logged',
        'is_labor_only',
        'materials_stage',
        'is_under_investigation',
        'ssu_notation',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'completed_at',
        'updated_at',
        'is_project',
        'project_title',
        'project_target_duration',
        'project_target_date',
        'project_manpower',
        'project_remarks',
        'project_actual_start',
        'project_actual_completion',
        'project_working_days',
        'extension_days',
        'extended_completion_date',
        'extension_reason',
        'overtime_hours',
        'eodb_tier',
        'eodb_days',
        'target_completion_date',
        'verification_status',
        'accomplishment_report_path',
        'accomplishment_notes',
        'verified_by_user_id',
        'verified_at',
    ];

    // -------------------------------------------------------------------------
    // Query Helpers
    // -------------------------------------------------------------------------

    /**
     * Get all active (not archived / pending action) tickets for a specific user/requestor.
     * Strictly mutually exclusive with getArchivedByUser.
     */
    public function getActiveByUser(string $userId, ?int $limit = null, ?int $offset = null): array
    {
        $db = \Config\Database::connect();

        $builder = $this->where('user_id', $userId)
                    ->where('(is_archived = 0 OR is_archived IS NULL)')
                    ->whereNotIn('status', ['closed', 'completed', 'declined', 'cancelled'])
                    ->where("id NOT IN (SELECT ticket_id FROM ticket_feedbacks WHERE user_id = {$db->escape($userId)})")
                    ->orderBy('submitted_at', 'DESC');

        if ($limit !== null) {
            $builder->limit($limit, $offset ?? 0);
        }

        return $builder->findAll();
    }

    /**
     * Get all archived/completed tickets for a specific user.
     * Includes any ticket that is fully closed/archived, has a terminal status,
     * or has feedback submitted by the user.
     */
    public function getArchivedByUser(string $userId, ?int $limit = null, ?int $offset = null): array
    {
        $db = \Config\Database::connect();

        $builder = $this->where('user_id', $userId)
                    ->groupStart()
                        ->where('is_archived', 1)
                        ->orWhereIn('status', ['closed', 'completed', 'declined', 'cancelled'])
                        ->orWhere("id IN (SELECT ticket_id FROM ticket_feedbacks WHERE user_id = {$db->escape($userId)})")
                    ->groupEnd()
                    ->orderBy('submitted_at', 'DESC');

        if ($limit !== null) {
            $builder->limit($limit, $offset ?? 0);
        }

        return $builder->findAll();
    }

    /**
     * Get the pending ticket queue for a specific unit (admin dashboard).
     */
    public function getPendingQueue(int $unitId, ?int $limit = null, ?int $offset = null): array
    {
        $builder = $this->select('tickets.*, users.first_name, users.last_name, users.email, users.role as requester_role, users.student_id_number, users.contact_number as requester_contact')
                    ->join('users', 'users.id = tickets.user_id', 'left')
                    ->where('tickets.unit_id', $unitId)
                    ->where('tickets.status', 'pending')
                    ->where('(tickets.is_approval_delayed = 0 OR tickets.is_approval_delayed IS NULL)')
                    ->where('(tickets.is_under_investigation = 0 OR tickets.is_under_investigation IS NULL)')
                    ->where('(tickets.is_archived = 0 OR tickets.is_archived IS NULL)')
                    ->orderBy('tickets.submitted_at', 'ASC');

        if ($limit !== null) {
            $builder->limit($limit, $offset ?? 0);
        }

        return $builder->findAll();
    }

    /**
     * Get tickets delayed for approval (e.g., awaiting procurement of materials) for a unit.
     */
    public function getApprovalDelayedQueue(int $unitId, ?int $limit = null, ?int $offset = null): array
    {
        $builder = $this->select('tickets.*, users.first_name, users.last_name, users.email, users.role as requester_role, users.student_id_number, users.contact_number as requester_contact, staff.first_name as delayed_by_first_name, staff.last_name as delayed_by_last_name')
                    ->join('users', 'users.id = tickets.user_id', 'left')
                    ->join('users as staff', 'staff.id = tickets.approval_delayed_by', 'left')
                    ->where('tickets.unit_id', $unitId)
                    ->where('tickets.status', 'pending')
                    ->where('tickets.is_approval_delayed', 1)
                    ->where('(tickets.is_archived = 0 OR tickets.is_archived IS NULL)')
                    ->orderBy('tickets.approval_delayed_at', 'DESC');

        if ($limit !== null) {
            $builder->limit($limit, $offset ?? 0);
        }

        return $builder->findAll();
    }

    /**
     * Get SSU Incident Report tickets currently under investigation (not yet archived).
     */
    public function getUnderInvestigationQueue(int $unitId, ?int $limit = null, ?int $offset = null): array
    {
        $builder = $this->select('tickets.*, users.first_name, users.last_name, users.email, users.role as requester_role, users.student_id_number, users.contact_number as requester_contact')
                    ->join('users', 'users.id = tickets.user_id', 'left')
                    ->where('tickets.unit_id', $unitId)
                    ->where('tickets.service_type', 'Incident Report')
                    ->where('tickets.is_under_investigation', 1)
                    ->where('(tickets.is_archived = 0 OR tickets.is_archived IS NULL)')
                    ->orderBy('tickets.submitted_at', 'ASC');

        if ($limit !== null) {
            $builder->limit($limit, $offset ?? 0);
        }

        return $builder->findAll();
    }

    /**
     * Get approved/scheduled tickets (admin dispatch queue) for a unit.
     */
    public function getDispatchQueue(int $unitId, ?int $limit = null, ?int $offset = null): array
    {
        $builder = $this->select('tickets.*, users.first_name, users.last_name, users.email, users.role as requester_role, users.student_id_number, users.contact_number as requester_contact')
                    ->join('users', 'users.id = tickets.user_id', 'left')
                    ->where('tickets.unit_id', $unitId)
                    ->whereIn('tickets.status', ['approved'])
                    ->where('(tickets.is_archived = 0 OR tickets.is_archived IS NULL)')
                    ->orderBy('tickets.submitted_at', 'ASC');

        if ($limit !== null) {
            $builder->limit($limit, $offset ?? 0);
        }

        return $builder->findAll();
    }

    /**
     * Get actively in-progress tickets for a unit.
     */
    public function getActiveTickets(int $unitId, ?int $limit = null, ?int $offset = null): array
    {
        $builder = $this->select('tickets.*, users.first_name, users.last_name, users.email, users.role as requester_role, users.student_id_number, users.contact_number as requester_contact')
                    ->join('users', 'users.id = tickets.user_id', 'left')
                    ->where('tickets.unit_id', $unitId)
                    ->whereIn('tickets.status', ['processing', 'resolved'])
                    ->where('(tickets.is_archived = 0 OR tickets.is_archived IS NULL)')
                    ->orderBy('tickets.submitted_at', 'ASC');

        if ($limit !== null) {
            $builder->limit($limit, $offset ?? 0);
        }

        return $builder->findAll();
    }

    /**
     * Get completed/archived tickets for a unit (admin archive).
     */
    public function getArchivedByUnit(int $unitId, array $filters = [], ?int $limit = null, ?int $offset = null): array
    {
        $builder = $this->select('tickets.*, users.first_name, users.last_name, users.email, users.role as requester_role, users.student_id_number, users.contact_number as requester_contact')
                        ->join('users', 'users.id = tickets.user_id', 'left')
                        ->where('tickets.unit_id', $unitId)
                        ->where('tickets.is_archived', 1)
                        ->orderBy('tickets.completed_at', 'DESC');

        if (!empty($filters['search'])) {
            $builder->like('tickets.id', $filters['search']);
        }

        if (!empty($filters['status'])) {
            $builder->where('tickets.status', $filters['status']);
        }

        if (!empty($filters['date_from'])) {
            $builder->where('tickets.submitted_at >=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $builder->where('tickets.submitted_at <=', $filters['date_to'] . ' 23:59:59');
        }

        if ($limit !== null) {
            $builder->limit($limit, $offset ?? 0);
        }

        return $builder->findAll();
    }

    /**
     * Generate the next sequential ticket ID for a given unit within the current year.
     * Format: {UNIT_CODE}-TIC-{N}-{YEAR}  e.g. FGMU-TIC-43-2026
     *
     * @param string $unitCode   Sub-unit code (FGMU, LEAU, SSU)
     * @param int    $unitId     Corresponding unit_id FK
     * @param int    $batchOffset Extra offset to add when generating multiple tickets
     *                           for the same unit in a single request (0-indexed within batch)
     */
    public function generateTicketId(string $unitCode, int $unitId, int $batchOffset = 0): string
    {
        $year   = date('Y');
        $prefix = strtoupper($unitCode) . '-TIC-';
        $suffix = '-' . $year;
        $like   = $prefix . '%' . $suffix;

        // Use the active transaction connection so that rows inserted earlier
        // in the same transaction are visible to this MAX() query.
        $db  = \Config\Database::connect();
        $row = $db->query(
            "SELECT MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(id, '-TIC-', -1), ?, 1) AS UNSIGNED)) AS max_seq
             FROM tickets
             WHERE unit_id = ? AND id LIKE ?",
            [$suffix, $unitId, $like]
        )->getRowArray();

        $next = (int) ($row['max_seq'] ?? 0) + 1 + $batchOffset;

        return $prefix . $next . $suffix;
    }

    /**
     * Generate the next internal project ID for an announcement project within the current year.
     * Format: {UNIT_CODE}-PRJ-{N}-{YEAR} e.g. FGMU-PRJ-1-2026
     */
    public function generateProjectId(string $unitCode, int $unitId, int $batchOffset = 0): string
    {
        $year   = date('Y');
        $prefix = strtoupper($unitCode) . '-PRJ-';
        $suffix = '-' . $year;
        $like   = $prefix . '%' . $suffix;

        $db  = \Config\Database::connect();
        $row = $db->query(
            "SELECT MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(id, '-PRJ-', -1), ?, 1) AS UNSIGNED)) AS max_seq
             FROM tickets
             WHERE unit_id = ? AND id LIKE ?",
            [$suffix, $unitId, $like]
        )->getRowArray();

        $next = (int) ($row['max_seq'] ?? 0) + 1 + $batchOffset;

        return $prefix . $next . $suffix;
    }

    /**
     * Get a summary count of tickets by status for a unit (for dashboard stats),
     * with optional period filtering (all, year, quarter, month).
     */
    public function getStatsByUnit(?int $unitId = null, array $filters = []): array
    {
        $db = \Config\Database::connect();

        $allTimeSql = "
            SELECT
                COUNT(*) AS all_time_total,
                SUM(CASE WHEN status = 'pending' AND (is_approval_delayed = 0 OR is_approval_delayed IS NULL) THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'pending' AND is_approval_delayed = 1 THEN 1 ELSE 0 END) AS approval_delayed,
                SUM(CASE WHEN status = 'approved'   THEN 1 ELSE 0 END) AS approved,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processing,
                SUM(CASE WHEN status = 'processing' AND (status_label LIKE '%Dispatch%' OR status_label LIKE '%Schedul%' OR status_label LIKE '%Waiting%') THEN 1 ELSE 0 END) AS scheduled,
                SUM(CASE WHEN status = 'processing' AND (status_label LIKE '%Route%' OR status_label LIKE '%Started%' OR status_label LIKE '%Working%' OR status_label LIKE '%In Progress%') THEN 1 ELSE 0 END) AS active_working,
                SUM(CASE WHEN status IN ('resolved', 'closed') THEN 1 ELSE 0 END) AS all_time_resolved,
                SUM(CASE WHEN status = 'declined'   THEN 1 ELSE 0 END) AS all_time_declined,
                SUM(CASE WHEN is_archived = 1       THEN 1 ELSE 0 END) AS archived
            FROM tickets
        ";

        if ($unitId !== null) {
            $allTimeSql .= " WHERE unit_id = ?";
            $allTimeRow = $db->query($allTimeSql, [$unitId])->getRowArray() ?? [];
        } else {
            $allTimeRow = $db->query($allTimeSql)->getRowArray() ?? [];
        }

        // Available historical years from tickets table
        $yearsResult = $db->query("SELECT DISTINCT YEAR(submitted_at) AS yr FROM tickets WHERE submitted_at IS NOT NULL ORDER BY yr DESC")->getResultArray();
        $availableYears = array_values(array_filter(array_map(fn($r) => (int)$r['yr'], $yearsResult)));
        $currentYear = (int)date('Y');
        if (!in_array($currentYear, $availableYears)) {
            array_unshift($availableYears, $currentYear);
        }

        // Period filter resolution
        $period = strtolower((string)($filters['period'] ?? 'all'));
        if (!in_array($period, ['all', 'year', 'quarter', 'month', 'day', 'daily'])) {
            $period = 'all';
        }
        if ($period === 'daily') {
            $period = 'day';
        }

        $yearRaw = $filters['year'] ?? null;
        $year = ($yearRaw !== null && is_numeric($yearRaw)) ? (int)$yearRaw : $currentYear;

        $quarterRaw = $filters['quarter'] ?? null;
        $quarter = ($quarterRaw !== null && is_numeric($quarterRaw)) ? (int)$quarterRaw : (int)ceil(date('n') / 3);

        $monthRaw = $filters['month'] ?? null;
        $month = ($monthRaw !== null && is_numeric($monthRaw)) ? (int)$monthRaw : (int)date('n');

        $dateRaw = $filters['date'] ?? null;
        $targetDate = ($dateRaw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw)) ? $dateRaw : date('Y-m-d');

        $filteredTotal = (int)($allTimeRow['all_time_total'] ?? 0);
        $filteredResolved = (int)($allTimeRow['all_time_resolved'] ?? 0);
        $filteredDeclined = (int)($allTimeRow['all_time_declined'] ?? 0);
        $filterLabel = 'All Time';

        if ($period !== 'all') {
            $whereUnit = $unitId !== null ? "unit_id = " . (int)$unitId . " AND " : "";

            if ($period === 'year') {
                $subCond = "YEAR(submitted_at) = {$year}";
                $resCond = "status IN ('resolved', 'closed') AND YEAR(COALESCE(completed_at, submitted_at)) = {$year}";
                $decCond = "status = 'declined' AND YEAR(COALESCE(reviewed_at, updated_at, submitted_at)) = {$year}";
                $filterLabel = "Year {$year}";
            } elseif ($period === 'quarter') {
                $subCond = "YEAR(submitted_at) = {$year} AND QUARTER(submitted_at) = {$quarter}";
                $resCond = "status IN ('resolved', 'closed') AND YEAR(COALESCE(completed_at, submitted_at)) = {$year} AND QUARTER(COALESCE(completed_at, submitted_at)) = {$quarter}";
                $decCond = "status = 'declined' AND YEAR(COALESCE(reviewed_at, updated_at, submitted_at)) = {$year} AND QUARTER(COALESCE(reviewed_at, updated_at, submitted_at)) = {$quarter}";
                $qLabels = [1 => 'Q1 (Jan - Mar)', 2 => 'Q2 (Apr - Jun)', 3 => 'Q3 (Jul - Sep)', 4 => 'Q4 (Oct - Dec)'];
                $filterLabel = ($qLabels[$quarter] ?? "Q{$quarter}") . " {$year}";
            } elseif ($period === 'month') {
                $subCond = "YEAR(submitted_at) = {$year} AND MONTH(submitted_at) = {$month}";
                $resCond = "status IN ('resolved', 'closed') AND YEAR(COALESCE(completed_at, submitted_at)) = {$year} AND MONTH(COALESCE(completed_at, submitted_at)) = {$month}";
                $decCond = "status = 'declined' AND YEAR(COALESCE(reviewed_at, updated_at, submitted_at)) = {$year} AND MONTH(COALESCE(reviewed_at, updated_at, submitted_at)) = {$month}";
                $mName = date('F', mktime(0, 0, 0, $month, 10));
                $filterLabel = "{$mName} {$year}";
            } elseif ($period === 'day') {
                $subCond = "DATE(submitted_at) = '{$targetDate}'";
                $resCond = "status IN ('resolved', 'closed') AND DATE(COALESCE(completed_at, submitted_at)) = '{$targetDate}'";
                $decCond = "status = 'declined' AND DATE(COALESCE(reviewed_at, updated_at, submitted_at)) = '{$targetDate}'";
                $isToday = ($targetDate === date('Y-m-d'));
                $filterLabel = $isToday ? "Today (" . date('M j, Y') . ")" : date('F j, Y', strtotime($targetDate));
            }

            $filterSql = "
                SELECT
                    (SELECT COUNT(*) FROM tickets WHERE {$whereUnit} {$subCond}) AS f_total,
                    (SELECT COUNT(*) FROM tickets WHERE {$whereUnit} {$resCond}) AS f_resolved,
                    (SELECT COUNT(*) FROM tickets WHERE {$whereUnit} {$decCond}) AS f_declined
            ";
            $fRow = $db->query($filterSql)->getRowArray() ?? [];
            $filteredTotal = (int)($fRow['f_total'] ?? 0);
            $filteredResolved = (int)($fRow['f_resolved'] ?? 0);
            $filteredDeclined = (int)($fRow['f_declined'] ?? 0);
        }

        // Today's Real-time Daily KPIs (always returned regardless of selected filter)
        $whereUnitForToday = $unitId !== null ? "unit_id = " . (int)$unitId . " AND " : "";
        $todaySql = "
            SELECT
                (SELECT COUNT(*) FROM tickets WHERE {$whereUnitForToday} DATE(submitted_at) = CURDATE()) AS daily_total,
                (SELECT COUNT(*) FROM tickets WHERE {$whereUnitForToday} status IN ('resolved', 'closed') AND DATE(COALESCE(completed_at, submitted_at)) = CURDATE()) AS daily_resolved,
                (SELECT COUNT(*) FROM tickets WHERE {$whereUnitForToday} status = 'declined' AND DATE(COALESCE(reviewed_at, updated_at, submitted_at)) = CURDATE()) AS daily_declined
        ";
        $todayRow = $db->query($todaySql)->getRowArray() ?? [];
        $dailyTotal = (int)($todayRow['daily_total'] ?? 0);
        $dailyResolved = (int)($todayRow['daily_resolved'] ?? 0);
        $dailyDeclined = (int)($todayRow['daily_declined'] ?? 0);

        // 7-Day Activity Breakdown (recent trend of daily requests vs completed jobs)
        $recentDays = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $recentDays[$d] = [
                'date'            => $d,
                'day_name'        => date('D', strtotime($d)),
                'label'           => ($i === 0) ? 'Today' : date('M j', strtotime($d)),
                'is_today'        => ($i === 0),
                'total_requests'  => 0,
                'completed_jobs'  => 0,
                'declined'        => 0,
                'completion_rate' => 0,
            ];
        }

        $startDate = date('Y-m-d', strtotime('-6 days'));
        $dailySubmissions = $db->query("
            SELECT DATE(submitted_at) AS d, COUNT(*) AS count
            FROM tickets
            WHERE {$whereUnitForToday} DATE(submitted_at) >= '{$startDate}'
            GROUP BY DATE(submitted_at)
        ")->getResultArray();
        foreach ($dailySubmissions as $row) {
            if (isset($recentDays[$row['d']])) {
                $recentDays[$row['d']]['total_requests'] = (int)$row['count'];
            }
        }

        $dailyCompletions = $db->query("
            SELECT DATE(COALESCE(completed_at, submitted_at)) AS d, COUNT(*) AS count
            FROM tickets
            WHERE {$whereUnitForToday} status IN ('resolved', 'closed') 
              AND DATE(COALESCE(completed_at, submitted_at)) >= '{$startDate}'
            GROUP BY DATE(COALESCE(completed_at, submitted_at))
        ")->getResultArray();
        foreach ($dailyCompletions as $row) {
            if (isset($recentDays[$row['d']])) {
                $recentDays[$row['d']]['completed_jobs'] = (int)$row['count'];
            }
        }

        $dailyDeclines = $db->query("
            SELECT DATE(COALESCE(reviewed_at, updated_at, submitted_at)) AS d, COUNT(*) AS count
            FROM tickets
            WHERE {$whereUnitForToday} status = 'declined' 
              AND DATE(COALESCE(reviewed_at, updated_at, submitted_at)) >= '{$startDate}'
            GROUP BY DATE(COALESCE(reviewed_at, updated_at, submitted_at))
        ")->getResultArray();
        foreach ($dailyDeclines as $row) {
            if (isset($recentDays[$row['d']])) {
                $recentDays[$row['d']]['declined'] = (int)$row['count'];
            }
        }

        foreach ($recentDays as &$dayItem) {
            $req = $dayItem['total_requests'];
            $comp = $dayItem['completed_jobs'];
            $dayItem['completion_rate'] = $req > 0 ? min(100, round(($comp / $req) * 100)) : ($comp > 0 ? 100 : 0);
        }
        unset($dayItem);
        $dailyBreakdown = array_values($recentDays);

        return [
            'total'             => $filteredTotal,
            'resolved'          => $filteredResolved,
            'declined'          => $filteredDeclined,
            'daily_total'       => $dailyTotal,
            'daily_resolved'    => $dailyResolved,
            'daily_declined'    => $dailyDeclined,
            'daily_breakdown'   => $dailyBreakdown,
            'all_time_total'    => (int)($allTimeRow['all_time_total'] ?? 0),
            'all_time_resolved' => (int)($allTimeRow['all_time_resolved'] ?? 0),
            'all_time_declined' => (int)($allTimeRow['all_time_declined'] ?? 0),
            'pending'           => (int)($allTimeRow['pending'] ?? 0),
            'approval_delayed'  => (int)($allTimeRow['approval_delayed'] ?? 0),
            'approved'          => (int)($allTimeRow['approved'] ?? 0),
            'processing'        => (int)($allTimeRow['processing'] ?? 0),
            'scheduled'         => (int)($allTimeRow['scheduled'] ?? 0),
            'active_working'    => (int)($allTimeRow['active_working'] ?? 0),
            'archived'          => (int)($allTimeRow['archived'] ?? 0),
            'filter'            => [
                'period'  => $period,
                'year'    => $year,
                'quarter' => $quarter,
                'month'   => $month,
                'date'    => $targetDate,
                'label'   => $filterLabel,
            ],
            'available_years'   => $availableYears,
        ];
    }

    public function getAdvancedStatsByUnit(?int $unitId = null, array $filters = []): array
    {
        $db = \Config\Database::connect();
        $stats = $this->getStatsByUnit($unitId, $filters);
        
        $whereUnit = $unitId ? "WHERE t.unit_id = " . (int)$unitId : "";
        $whereUnitTickets = $unitId ? "WHERE unit_id = " . (int)$unitId : "";

        // 1. Averages
        if ($unitId) {
            $feedbackModel = new TicketFeedbackModel();
            $averages = $feedbackModel->getUnitAverageRatings($unitId);
        } else {
            $averages = $db->query("
                SELECT 
                    AVG(courtesy_rating) as avg_courtesy, 
                    AVG(quality_rating) as avg_quality, 
                    AVG(efficiency_rating) as avg_efficiency, 
                    AVG(timeliness_rating) as avg_timeliness, 
                    AVG(cleanliness_rating) as avg_cleanliness 
                FROM ticket_feedbacks
            ")->getRowArray();
        }
        $stats['feedback_averages'] = $averages;

        // 2. Completion Health (includes early finished detection)
        $completion = $db->query("
            SELECT 
                CASE 
                    WHEN tf.completion_status = 'early' THEN 'early'
                    WHEN tf.completion_status = 'on-time' AND t.completed_at IS NOT NULL AND COALESCE(t.extended_completion_date, t.target_completion_date, t.project_target_date) IS NOT NULL AND DATE(t.completed_at) < DATE(COALESCE(t.extended_completion_date, t.target_completion_date, t.project_target_date)) THEN 'early'
                    ELSE tf.completion_status 
                END AS completion_status,
                COUNT(*) as count 
            FROM ticket_feedbacks tf 
            JOIN tickets t ON t.id = tf.ticket_id 
            $whereUnit 
            GROUP BY 
                CASE 
                    WHEN tf.completion_status = 'early' THEN 'early'
                    WHEN tf.completion_status = 'on-time' AND t.completed_at IS NOT NULL AND COALESCE(t.extended_completion_date, t.target_completion_date, t.project_target_date) IS NOT NULL AND DATE(t.completed_at) < DATE(COALESCE(t.extended_completion_date, t.target_completion_date, t.project_target_date)) THEN 'early'
                    ELSE tf.completion_status 
                END
        ")->getResultArray();
        $stats['completion_health'] = $completion;

        // 3. Delay Reasons (for beyond-time)
        $delayReasons = $db->query("
            SELECT r.reason_code, COUNT(*) as count 
            FROM ticket_feedback_delay_items di
            JOIN feedback_delay_reasons r ON r.id = di.delay_reason_id
            JOIN ticket_feedbacks tf ON tf.id = di.feedback_id
            JOIN tickets t ON t.id = tf.ticket_id
            $whereUnit " . ($whereUnit ? "AND" : "WHERE") . " tf.completion_status = 'beyond-time'
            GROUP BY r.reason_code
        ")->getResultArray();
        $stats['delay_reasons'] = $delayReasons;

        // 4. Non-completion Barriers
        $nonCompletion = $db->query("
            SELECT r.reason_code, COUNT(*) as count 
            FROM ticket_feedback_delay_items di
            JOIN feedback_delay_reasons r ON r.id = di.delay_reason_id
            JOIN ticket_feedbacks tf ON tf.id = di.feedback_id
            JOIN tickets t ON t.id = tf.ticket_id
            $whereUnit " . ($whereUnit ? "AND" : "WHERE") . " tf.completion_status = 'not-completed'
            GROUP BY r.reason_code
        ")->getResultArray();
        $stats['non_completion'] = $nonCompletion;

        // 4.5. Approval Delay Reasons (Pre-Work Deferred / Delayed Approvals)
        $appDelayWhere = $unitId 
            ? "WHERE t.unit_id = " . (int)$unitId . " AND t.approval_delay_reason IS NOT NULL AND TRIM(t.approval_delay_reason) != ''" 
            : "WHERE t.approval_delay_reason IS NOT NULL AND TRIM(t.approval_delay_reason) != ''";

        $approvalDelayReasons = $db->query("
            SELECT 
                TRIM(t.approval_delay_reason) AS reason_text, 
                COUNT(*) AS count 
            FROM tickets t 
            $appDelayWhere 
            GROUP BY TRIM(t.approval_delay_reason)
            ORDER BY count DESC
        ")->getResultArray();
        $stats['approval_delay_reasons'] = $approvalDelayReasons;

        // 5. Service Request Frequency
        $freq = [];
        $periods = [
            'Day' => "submitted_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            'Week' => "submitted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'Month' => "submitted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            'Year' => "submitted_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)"
        ];
        
        foreach ($periods as $periodKey => $condition) {
            $where = $unitId 
                ? "WHERE unit_id = " . (int)$unitId . " AND $condition AND (is_project = 0 OR is_project IS NULL)" 
                : "WHERE $condition AND (is_project = 0 OR is_project IS NULL)";
            $rows = $db->query("
                SELECT COALESCE(NULLIF(service_type, ''), 'General Maintenance') AS service_type
                FROM tickets 
                $where
            ")->getResultArray();

            // A ticket may bundle multiple services from the same unit
            // (comma-separated service_type). Count each selected service
            // as an individual service request instead of per ticket.
            $splitCounts = [];
            foreach ($rows as $r) {
                $parts = explode(',', (string)($r['service_type'] ?? ''));
                $services = [];
                foreach ($parts as $part) {
                    $name = trim($part);
                    if ($name !== '') {
                        $services[] = $name;
                    }
                }
                if (empty($services)) {
                    $services[] = 'General Maintenance';
                }
                foreach (array_values(array_unique($services)) as $serviceName) {
                    $splitCounts[$serviceName] = ($splitCounts[$serviceName] ?? 0) + 1;
                }
            }
            arsort($splitCounts);

            $counts = [];
            foreach ($splitCounts as $serviceName => $c) {
                $counts[] = ['service_type' => $serviceName, 'count' => $c];
            }
            
            $freq[$periodKey] = $counts;
        }
        $stats['service_freq'] = $freq;

        // SSU specific analytics
        if ($unitId === 3) {
            $stats['incident_heatmap'] = $db->query("
                SELECT it.type_name as category, COUNT(*) as count
                FROM ssu_incident_type_items iti
                JOIN ssu_incident_types it ON it.id = iti.incident_type_id
                GROUP BY it.type_name
            ")->getResultArray();
        }

        return $stats;
    }
}
