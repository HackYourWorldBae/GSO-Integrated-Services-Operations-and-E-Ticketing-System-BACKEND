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
        'is_emergency',
        'decline_reason',
        'current_step',
        'location',
        'office_room',
        'is_archived',
        'materials_logged',
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
    public function getActiveByUser(string $userId): array
    {
        $db = \Config\Database::connect();

        return $this->where('user_id', $userId)
                    ->where('is_archived', 0)
                    ->whereNotIn('status', ['closed', 'completed', 'declined', 'cancelled'])
                    ->where("id NOT IN (SELECT ticket_id FROM ticket_feedbacks WHERE user_id = {$db->escape($userId)})")
                    ->orderBy('submitted_at', 'DESC')
                    ->findAll();
    }

    /**
     * Get all archived/completed tickets for a specific user.
     * Includes any ticket that is fully closed/archived, has a terminal status,
     * or has feedback submitted by the user.
     */
    public function getArchivedByUser(string $userId): array
    {
        $db = \Config\Database::connect();

        return $this->where('user_id', $userId)
                    ->groupStart()
                        ->where('is_archived', 1)
                        ->orWhereIn('status', ['closed', 'completed', 'declined', 'cancelled'])
                        ->orWhere("id IN (SELECT ticket_id FROM ticket_feedbacks WHERE user_id = {$db->escape($userId)})")
                    ->groupEnd()
                    ->orderBy('submitted_at', 'DESC')
                    ->findAll();
    }

    /**
     * Get the pending ticket queue for a specific unit (admin dashboard).
     */
    public function getPendingQueue(int $unitId): array
    {
        return $this->select('tickets.*, users.first_name, users.last_name, users.email, users.role as requester_role, users.student_id_number, users.contact_number as requester_contact')
                    ->join('users', 'users.id = tickets.user_id', 'left')
                    ->where('tickets.unit_id', $unitId)
                    ->where('tickets.status', 'pending')
                    ->where('tickets.is_archived', 0)
                    ->orderBy('tickets.submitted_at', 'ASC')
                    ->findAll();
    }

    /**
     * Get SSU Incident Report tickets currently under investigation (not yet archived).
     */
    public function getUnderInvestigationQueue(int $unitId): array
    {
        return $this->where('unit_id', $unitId)
                    ->where('service_type', 'Incident Report')
                    ->where('is_under_investigation', 1)
                    ->where('is_archived', 0)
                    ->orderBy('submitted_at', 'ASC')
                    ->findAll();
    }

    /**
     * Get approved/scheduled tickets (dispatcher queue) for a unit.
     */
    public function getDispatchQueue(int $unitId): array
    {
        return $this->where('unit_id', $unitId)
                    ->whereIn('status', ['approved'])
                    ->where('is_archived', 0)
                    ->orderBy('submitted_at', 'ASC')
                    ->findAll();
    }

    /**
     * Get actively in-progress tickets for a unit.
     */
    public function getActiveTickets(int $unitId): array
    {
        return $this->where('unit_id', $unitId)
                    ->whereIn('status', ['processing', 'resolved'])
                    ->where('is_archived', 0)
                    ->orderBy('submitted_at', 'ASC')
                    ->findAll();
    }

    /**
     * Get completed/archived tickets for a unit (admin archive).
     */
    public function getArchivedByUnit(int $unitId, array $filters = []): array
    {
        $builder = $this->where('unit_id', $unitId)
                        ->where('is_archived', 1)
                        ->orderBy('completed_at', 'DESC');

        if (!empty($filters['search'])) {
            $builder->like('id', $filters['search']);
        }

        if (!empty($filters['status'])) {
            $builder->where('status', $filters['status']);
        }

        if (!empty($filters['date_from'])) {
            $builder->where('submitted_at >=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $builder->where('submitted_at <=', $filters['date_to'] . ' 23:59:59');
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
                SUM(CASE WHEN status = 'pending'    THEN 1 ELSE 0 END) AS pending,
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
        if (!in_array($period, ['all', 'year', 'quarter', 'month'])) {
            $period = 'all';
        }

        $yearRaw = $filters['year'] ?? null;
        $year = ($yearRaw !== null && is_numeric($yearRaw)) ? (int)$yearRaw : $currentYear;

        $quarterRaw = $filters['quarter'] ?? null;
        $quarter = ($quarterRaw !== null && is_numeric($quarterRaw)) ? (int)$quarterRaw : (int)ceil(date('n') / 3);

        $monthRaw = $filters['month'] ?? null;
        $month = ($monthRaw !== null && is_numeric($monthRaw)) ? (int)$monthRaw : (int)date('n');

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

        return [
            'total'             => $filteredTotal,
            'resolved'          => $filteredResolved,
            'declined'          => $filteredDeclined,
            'all_time_total'    => (int)($allTimeRow['all_time_total'] ?? 0),
            'all_time_resolved' => (int)($allTimeRow['all_time_resolved'] ?? 0),
            'all_time_declined' => (int)($allTimeRow['all_time_declined'] ?? 0),
            'pending'           => (int)($allTimeRow['pending'] ?? 0),
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

        // 2. Completion Health
        $completion = $db->query("
            SELECT tf.completion_status, COUNT(*) as count 
            FROM ticket_feedbacks tf 
            JOIN tickets t ON t.id = tf.ticket_id 
            $whereUnit 
            GROUP BY tf.completion_status
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
            $counts = $db->query("
                SELECT service_type, COUNT(*) as count 
                FROM tickets 
                $where 
                GROUP BY service_type
            ")->getResultArray();
            
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
