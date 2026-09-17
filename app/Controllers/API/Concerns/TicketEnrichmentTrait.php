<?php

namespace App\Controllers\API\Concerns;

use Config\Database;

/**
 * TicketEnrichmentTrait - shared ticket enrichment helpers.
 *
 * Methods moved verbatim from TicketController (bodies untouched):
 *  enrichTickets, buildMaterialsMap, buildDetailMap, buildAttachmentMap,
 *  buildAssignmentMap, formatServicesList.
 * Host controllers must define UNIT_MAP and the model properties copied
 * from the original TicketController header.
 */
trait TicketEnrichmentTrait
{
    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    /**
     * Enrich a list of tickets with their unit-specific details.
     * Uses a single query per detail type to avoid N+1 queries.
     */
    private function enrichTickets(array $tickets): array
    {
        if (empty($tickets)) {
            return $tickets;
        }

        $ticketIds = array_column($tickets, 'id');
        $db        = Database::connect();

        // Load all relevant detail records in bulk
        $fgmuDetails  = $this->buildDetailMap($db, 'fgmu_ticket_details',      'ticket_id', $ticketIds);
        $leauDetails  = $this->buildDetailMap($db, 'leau_ticket_details',      'ticket_id', $ticketIds);
        $ssuIrDetails = $this->buildDetailMap($db, 'ssu_incident_details',     'ticket_id', $ticketIds);
        $assignmentsData = $this->buildAssignmentMap($db, $ticketIds);
        $assignments     = $assignmentsData['single'] ?? [];
        $assignmentsList = $assignmentsData['all'] ?? [];
        $feedbacks       = $this->buildDetailMap($db, 'ticket_feedbacks',         'ticket_id', $ticketIds);
        $materialsMap    = $this->buildMaterialsMap($db, $ticketIds);

        // Load user/requester profiles in bulk for tickets
        $userIds = array_filter(array_unique(array_column($tickets, 'user_id')));
        $usersMap = [];
        if (!empty($userIds)) {
            $userRows = $db->table('users')
                           ->select('id, first_name, last_name, email, role as requester_role, student_id_number, student_type, organization_name, college, contact_number as requester_contact')
                           ->whereIn('id', $userIds)
                           ->get()
                           ->getResultArray();
            foreach ($userRows as $u) {
                $usersMap[$u['id']] = $u;
            }
        }

        // Enrich SSU Incident Reports with 3NF bridge table arrays (incidents, information, roles)
        if (!empty($ssuIrDetails)) {
            $irIds = array_keys($ssuIrDetails);
            $placeholders = implode(',', array_fill(0, count($irIds), '?'));

            $typeRows = $db->query("
                SELECT i.ticket_id, t.type_name 
                FROM ssu_incident_type_items i 
                JOIN ssu_incident_types t ON t.id = i.incident_type_id 
                WHERE i.ticket_id IN ({$placeholders})
            ", $irIds)->getResultArray();

            $issueRows = $db->query("
                SELECT i.ticket_id, t.issue_name 
                FROM ssu_incident_issue_items i 
                JOIN ssu_incident_issues t ON t.id = i.issue_id 
                WHERE i.ticket_id IN ({$placeholders})
            ", $irIds)->getResultArray();

            $roleRows = $db->query("
                SELECT i.ticket_id, t.role_name 
                FROM ssu_incident_role_items i 
                JOIN ssu_incident_roles t ON t.id = i.role_id 
                WHERE i.ticket_id IN ({$placeholders})
            ", $irIds)->getResultArray();

            foreach ($ssuIrDetails as $id => &$detail) {
                $detail['incidents']   = array_values(array_column(array_filter($typeRows,  fn($r) => $r['ticket_id'] === $id), 'type_name'));
                $detail['information'] = array_values(array_column(array_filter($issueRows, fn($r) => $r['ticket_id'] === $id), 'issue_name'));
                $detail['roles']       = array_values(array_column(array_filter($roleRows,  fn($r) => $r['ticket_id'] === $id), 'role_name'));
                $detail['reportedBy']  = [
                    'printedName' => $detail['reporter_name'] ?? '',
                    'signature'   => $detail['reporter_signature'] ?? '',
                    'roles'       => $detail['roles'],
                ];
            }
            unset($detail);
        }

        $attachmentsMap = $this->buildAttachmentMap($db, $ticketIds);

        foreach ($tickets as &$ticket) {
            $id = $ticket['id'];
            $ticket['unit_id'] = (int) $ticket['unit_id'];
            
            // Critical for frontend timeline and categorizations
            $ticket['unit_code'] = array_search($ticket['unit_id'], self::UNIT_MAP) ?: null;

            $ticket['details'] = match((int) $ticket['unit_id']) {
                1       => $fgmuDetails[$id]  ?? null,
                2       => $leauDetails[$id]  ?? null,
                3       => $ssuIrDetails[$id] ?? null,
                default => null,
            };

            // Attach user/requester profile
            $u = $usersMap[$ticket['user_id']] ?? null;
            if ($u) {
                if (empty($ticket['first_name']))        $ticket['first_name']        = $u['first_name'];
                if (empty($ticket['last_name']))         $ticket['last_name']         = $u['last_name'];
                if (empty($ticket['email']))             $ticket['email']             = $u['email'];
                if (empty($ticket['requester_role']))    $ticket['requester_role']    = $u['requester_role'];
                if (empty($ticket['student_id_number'])) $ticket['student_id_number'] = $u['student_id_number'];
                if (empty($ticket['requester_contact'])) $ticket['requester_contact'] = $u['requester_contact'];
                if (empty($ticket['contact_number']))    $ticket['contact_number']    = $u['requester_contact'];
                if (empty($ticket['student_type']))      $ticket['student_type']      = $u['student_type'] ?? null;
                if (empty($ticket['organization_name'])) $ticket['organization_name'] = $u['organization_name'] ?? null;
                if (empty($ticket['college']))           $ticket['college']           = $u['college'] ?? null;
                $ticket['requester'] = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                $ticket['requestedBy'] = $ticket['requester'];
                $ticket['requested_by'] = $ticket['requester'];
                $ticket['user'] = $u;
            } else if (!empty($ticket['first_name']) || !empty($ticket['last_name'])) {
                $ticket['requester'] = trim(($ticket['first_name'] ?? '') . ' ' . ($ticket['last_name'] ?? ''));
                $ticket['requestedBy'] = $ticket['requester'];
                $ticket['requested_by'] = $ticket['requester'];
                if (empty($ticket['contact_number']) && !empty($ticket['requester_contact'])) {
                    $ticket['contact_number'] = $ticket['requester_contact'];
                }
            }

            // Normalize nature of work and job particulars
            if (empty($ticket['service']) && !empty($ticket['service_type'])) {
                $ticket['service'] = $ticket['service_type'];
            }
            if (empty($ticket['job_description']) && !empty($ticket['description'])) {
                $ticket['job_description'] = $ticket['description'];
            }

            $ticket['assignment']          = $assignments[$id] ?? null;
            $ticket['assignments']         = $assignmentsList[$id] ?? [];
            $ticket['attachments']         = $attachmentsMap[$id] ?? [];
            $ticket['feedback']            = $feedbacks[$id] ?? null;
            $ticket['materials']           = $materialsMap[$id] ?? [];
            $ticket['total_material_cost'] = array_sum(array_column($ticket['materials'], 'total_price'));
            $ticket['is_labor_only']       = !empty($ticket['is_labor_only']) ? 1 : 0;
            $ticket['materials_stage']     = !empty($ticket['materials_stage']) && $ticket['materials_stage'] !== 'none'
                ? $ticket['materials_stage']
                : ($ticket['is_labor_only'] ? 'assessment' : (!empty($ticket['materials']) ? ($ticket['materials'][0]['stage'] ?? 'assessment') : 'none'));
            $ticket['materials_logged']    = !empty($ticket['materials_logged']) || !empty($ticket['materials']) || $ticket['is_labor_only'];

            $isIncidentReport = ((int) ($ticket['unit_id'] ?? 0) === 3) 
                || (($ticket['service_type'] ?? '') === 'Incident Report')
                || (($ticket['title'] ?? '') === 'Incident Report');

            $isUnscheduled = $isIncidentReport 
                || in_array($ticket['status'], ['pending', 'declined', 'cancelled'], true)
                || (empty($ticket['assignment']['working_days']) && empty($ticket['project_working_days']));

            $ticket['working_days']        = $isUnscheduled ? null : (!empty($ticket['assignment']['working_days']) 
                ? (int) $ticket['assignment']['working_days'] 
                : (!empty($ticket['project_working_days']) 
                    ? (int) $ticket['project_working_days'] 
                    : null));
            $ticket['implementation_date'] = $isUnscheduled ? null : ($ticket['assignment']['implementation_date'] 
                ?? (!empty($ticket['assignments'][0]['implementation_date']) ? $ticket['assignments'][0]['implementation_date'] : null)
                ?? ($ticket['project_target_date'] ?? null));
            $ticket['assigned_worker']     = $isUnscheduled ? null : ($ticket['assignment']['personnel_name'] ?? null);
            $ticket['assigned_profession'] = $isUnscheduled ? null : ($ticket['assignment']['specialty'] ?? null);

            $ticket['extension_days']           = $isUnscheduled ? 0 : (int) ($ticket['extension_days'] ?? 0);
            $ticket['extended_completion_date'] = $isUnscheduled ? null : ($ticket['extended_completion_date'] ?? null);
            $ticket['extension_reason']         = $isUnscheduled ? null : ($ticket['extension_reason'] ?? null);
            $ticket['overtime_hours']           = $isUnscheduled ? 0.0 : (float) ($ticket['overtime_hours'] ?? 0.0);
            $ticket['is_extended']              = !$isUnscheduled && (($ticket['extension_days'] > 0) || !empty($ticket['extended_completion_date']));
            $ticket['effective_target_date']    = $isUnscheduled ? null : (!empty($ticket['extended_completion_date'])
                ? $ticket['extended_completion_date']
                : (!empty($ticket['assignment']['implementation_date'])
                    ? $ticket['assignment']['implementation_date']
                    : (!empty($ticket['project_target_date']) ? $ticket['project_target_date'] : null)));

            $ticket['accomplishment_report_path'] = $ticket['accomplishment_report_path'] ?? null;
            $ticket['accomplishment_notes']       = $ticket['accomplishment_notes'] ?? null;
            $ticket['verification_status']        = $ticket['verification_status'] ?? 'pending_report';
            $ticket['verified_by_user_id']        = $ticket['verified_by_user_id'] ?? null;
            $ticket['verified_at']                = $ticket['verified_at'] ?? null;
            $ticket['eodb_tier']                  = $isUnscheduled ? null : ($ticket['eodb_tier'] ?? null);
            $ticket['eodb_days']                  = ($isUnscheduled || empty($ticket['eodb_days'])) ? null : (int) $ticket['eodb_days'];
            $ticket['target_completion_date']     = $isUnscheduled ? null : ($ticket['target_completion_date'] ?? null);
            $ticket['is_emergency']               = (int) ($ticket['is_emergency'] ?? 0);
            $ticket['is_vip']                     = (int) ($ticket['is_vip'] ?? 0);

            // Compute business working hours (skipping weekends & holidays)
            $startTimeStr = $ticket['assignment']['dispatched_at'] 
                ?? $ticket['assignment']['assigned_at'] 
                ?? $ticket['project_actual_start'] 
                ?? $ticket['assignment']['implementation_date'] 
                ?? null;

            if ($startTimeStr && in_array($ticket['status'], ['processing', 'resolved', 'closed'], true)) {
                try {
                    // If date-only string (e.g. YYYY-MM-DD), anchor to standard work start hour (8:00 AM)
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($startTimeStr))) {
                        $startTimeStr = trim($startTimeStr) . ' 08:00:00';
                    }
                    $startDt = new \DateTime($startTimeStr);
                    $endDt   = !empty($ticket['completed_at']) 
                        ? new \DateTime($ticket['completed_at']) 
                        : new \DateTime();

                    $ticket['computed_working_hours'] = \App\Libraries\WorkCalendar::calculateWorkingHours(
                        $startDt,
                        $endDt,
                        $ticket['overtime_hours']
                    );
                    $ticket['computed_working_duration'] = \App\Libraries\WorkCalendar::formatDuration(
                        $ticket['computed_working_hours'],
                        $ticket['overtime_hours']
                    );
                } catch (\Exception $e) {
                    $ticket['computed_working_hours']    = 0.0;
                    $ticket['computed_working_duration'] = 'N/A';
                }
            } else {
                $ticket['computed_working_hours']    = null;
                $ticket['computed_working_duration'] = null;
            }
        }
        unset($ticket);

        return $tickets;
    }

    /**
     * Query ticket_materials and return a map keyed by ticket_id.
     */
    private function buildMaterialsMap(\CodeIgniter\Database\ConnectionInterface $db, array $ticketIds): array
    {
        if (empty($ticketIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
        $rows = $db->query("
            SELECT tm.*, COALESCE(tm.ticket_id, ta.ticket_id) AS matched_ticket_id
            FROM ticket_materials tm
            LEFT JOIN ticket_assignments ta ON ta.id = tm.assignment_id
            WHERE tm.ticket_id IN ({$placeholders}) OR ta.ticket_id IN ({$placeholders})
            ORDER BY tm.id ASC
        ", array_merge($ticketIds, $ticketIds))->getResultArray();

        $map = [];
        foreach ($rows as $row) {
            $tId = $row['matched_ticket_id'];
            $row['quantity']    = (float) ($row['quantity'] ?? 1);
            $row['unit_price']  = (float) ($row['unit_price'] ?? 0);
            $row['total_price'] = (float) ($row['total_price'] ?? ($row['quantity'] * $row['unit_price']));
            $map[$tId][] = $row;
        }

        return $map;
    }

    /**
     * Query a detail table and return a map keyed by ticket_id.
     */
    private function buildDetailMap(\CodeIgniter\Database\ConnectionInterface $db, string $table, string $keyColumn, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        // Security: table/column identifiers cannot be bound as parameters, so
        // restrict them to the known detail tables (all internal callers pass
        // fixed literals; see enrichTickets above).
        $allowedTables = [
            'fgmu_ticket_details'  => ['ticket_id'],
            'leau_ticket_details'  => ['ticket_id'],
            'ssu_incident_details' => ['ticket_id'],
            'ticket_feedbacks'     => ['ticket_id'],
        ];
        if (!isset($allowedTables[$table]) || !in_array($keyColumn, $allowedTables[$table], true)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $db->query("SELECT * FROM {$table} WHERE {$keyColumn} IN ({$placeholders})", $ids)->getResultArray();

        $map = [];
        foreach ($rows as $row) {
            $map[$row[$keyColumn]] = $row;
        }

        return $map;
    }

    /**
     * Query attachments and return a grouped map keyed by ticket_id.
     */
    private function buildAttachmentMap(\CodeIgniter\Database\ConnectionInterface $db, array $ticketIds): array
    {
        if (empty($ticketIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
        $rows = $db->query("SELECT * FROM ticket_attachments WHERE ticket_id IN ({$placeholders})", $ticketIds)->getResultArray();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['ticket_id']][] = $row;
        }

        return $map;
    }

    /**
     * Query ticket_assignments and return a map keyed by ticket_id with single & full list formats.
     */
    private function buildAssignmentMap(\CodeIgniter\Database\ConnectionInterface $db, array $ticketIds): array
    {
        if (empty($ticketIds)) {
            return ['single' => [], 'all' => []];
        }

        $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));

        $rows = $db->query("
            SELECT ta.*, p.name AS personnel_name, p.specialty AS specialty, p.specialty AS profession, NULL AS personnel_contact
            FROM ticket_assignments ta
            LEFT JOIN personnel p ON p.id = ta.personnel_id
            WHERE ta.ticket_id IN ({$placeholders})
            ORDER BY ta.assigned_at ASC
        ", $ticketIds)->getResultArray();

        $map = [];
        $listMap = [];
        foreach ($rows as $row) {
            $tId = $row['ticket_id'];
            $listMap[$tId][] = $row;
            $pName = trim((string)($row['personnel_name'] ?? ''));
            $pSpec = trim((string)($row['specialty'] ?? ''));

            if (isset($map[$tId])) {
                if ($pName !== '') {
                    $existingNames = array_map('trim', explode(',', (string)$map[$tId]['personnel_name']));
                    if (!in_array($pName, $existingNames, true)) {
                        $map[$tId]['personnel_name'] .= ', ' . $pName;
                    }
                }
                if ($pSpec !== '') {
                    $existingSpecs = array_map('trim', explode(',', (string)($map[$tId]['specialty'] ?? '')));
                    if (!in_array($pSpec, $existingSpecs, true)) {
                        $currentSpec = trim((string)($map[$tId]['specialty'] ?? ''));
                        $map[$tId]['specialty'] = $currentSpec !== '' ? $currentSpec . ', ' . $pSpec : $pSpec;
                        $map[$tId]['profession'] = $map[$tId]['specialty'];
                    }
                }
                if (!empty($row['implementation_date'])) {
                    $map[$tId]['implementation_date'] = $row['implementation_date'];
                }
                if (!empty($row['working_days'])) {
                    $map[$tId]['working_days'] = $row['working_days'];
                }
                if (!empty($row['task_notes'])) {
                    $map[$tId]['task_notes'] = $row['task_notes'];
                }
                if (!empty($row['dispatcher_notes'])) {
                    $map[$tId]['dispatcher_notes'] = $row['dispatcher_notes'];
                }
            } else {
                $map[$tId] = $row;
                $map[$tId]['personnel_name'] = $pName;
                $map[$tId]['specialty'] = $pSpec;
                $map[$tId]['profession'] = $pSpec;
            }
        }

        return ['single' => $map, 'all' => $listMap];
    }

    private function formatServicesList(array $services): string
    {
        if (empty($services)) {
            return '';
        }

        $formatted = [];
        $count = count($services);
        
        foreach (array_values($services) as $i => $service) {
            $service = trim($service);
            if ($i < $count - 1) {
                $service = preg_replace('/(?:\s+Works?|\s+works?)$/i', '', $service);
            }
            $formatted[] = $service;
        }
        
        return implode(', ', $formatted);
    }
}
