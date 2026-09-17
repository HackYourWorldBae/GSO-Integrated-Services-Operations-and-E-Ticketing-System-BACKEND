<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Models\TicketModel;
use App\Models\FgmuTicketDetailModel;
use App\Models\LeauTicketDetailModel;
use App\Models\SsuIncidentDetailModel;
use App\Models\TicketAttachmentModel;
use App\Models\TicketLogModel;
use App\Models\NotificationModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * TicketController - intake & requestor scope.
 *
 * Retained (moved verbatim, bodies untouched):
 *  POST  /api/v1/tickets/intake, GET my-requests/completed, PATCH :id/cancel,
 *  POST/PATCH /projects*, GET projects*, GET projects/archives.
 * Relocated companions: TicketQueueController (queues/reads),
 *  TicketActionController (state transitions), TicketAttachmentController (files).
 * Shared enrichment lives in Concerns\TicketEnrichmentTrait.
 */
class TicketController extends BaseController
{
    use Concerns\TicketEnrichmentTrait;

    private TicketModel $ticketModel;
    private TicketLogModel $logModel;
    private NotificationModel $notificationModel;

    // Unit code => Unit ID mapping (mirrors the seeds in the schema)
    private const UNIT_MAP = [
        'FGMU' => 1,
        'LEAU' => 2,
        'SSU'  => 3,
    ];

    public function __construct()
    {
        $this->ticketModel       = new TicketModel();
        $this->logModel          = new TicketLogModel();
        $this->notificationModel = new NotificationModel();
    }

    // -------------------------------------------------------------------------
    // Requestor Dashboard
    // -------------------------------------------------------------------------

    /**
     * Get active (non-archived) tickets for the currently authenticated user.
     */
    public function myRequests(): ResponseInterface
    {
        $userId  = $this->currentUserId();
        $tickets = $this->ticketModel->getActiveByUser($userId);
        $tickets = $this->enrichTickets($tickets);

        // Strip internal staff documents (Job Order, Slips, Reports) from requester view
        foreach ($tickets as &$ticket) {
            if (!empty($ticket['attachments'])) {
                $ticket['attachments'] = array_values(array_filter($ticket['attachments'], function ($att) {
                    $name = strtolower(str_replace(['-', '_'], ' ', (string) ($att['file_name'] ?? '')));
                    return (
                        strpos($name, 'job order') === false &&
                        strpos($name, 'job request form') === false &&
                        strpos($name, 'joborder') === false &&
                        strpos($name, 'work order') === false &&
                        strpos($name, 'receipt slip') === false &&
                        strpos($name, 'material slip') === false &&
                        strpos($name, 'materials slip') === false &&
                        strpos($name, 'accomplishment') === false
                    );
                }));
            }
        }
        unset($ticket);

        return $this->successResponse('Active tickets retrieved.', ['tickets' => $tickets]);
    }

    /**
     * Get completed/archived tickets for the currently authenticated user.
     */
    public function completedRequests(): ResponseInterface
    {
        $userId  = $this->currentUserId();
        $tickets = $this->ticketModel->getArchivedByUser($userId);
        $tickets = $this->enrichTickets($tickets);

        // Strip internal staff documents (Job Order, Slips, Reports) from requester view
        foreach ($tickets as &$ticket) {
            if (!empty($ticket['attachments'])) {
                $ticket['attachments'] = array_values(array_filter($ticket['attachments'], function ($att) {
                    $name = strtolower(str_replace(['-', '_'], ' ', (string) ($att['file_name'] ?? '')));
                    return (
                        strpos($name, 'job order') === false &&
                        strpos($name, 'job request form') === false &&
                        strpos($name, 'joborder') === false &&
                        strpos($name, 'work order') === false &&
                        strpos($name, 'receipt slip') === false &&
                        strpos($name, 'material slip') === false &&
                        strpos($name, 'materials slip') === false &&
                        strpos($name, 'accomplishment') === false
                    );
                }));
            }
        }
        unset($ticket);

        return $this->successResponse('Completed tickets retrieved.', ['tickets' => $tickets]);
    }

    /**
     * Requestor self-service cancellation of their own pending ticket.
     * Only tickets in 'pending' status can be cancelled.
     *
     * PATCH /api/v1/tickets/:id/cancel
     */
    public function cancel(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);

        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        $userId = $this->currentUserId();
        $role   = $this->currentUserRole();

        // Must be the owner of the ticket or an elevated administrator
        if ((string) $ticket['user_id'] !== (string) $userId && !in_array($role, ['admin', 'superadmin'], true)) {
            return $this->forbiddenResponse('You do not have permission to cancel this ticket.');
        }

        // Only pending tickets can be cancelled by the requester
        if ($ticket['status'] !== 'pending') {
            return $this->errorResponse(
                "Ticket cannot be cancelled because its current status is '{$ticket['status']}'. Only pending requests can be cancelled.",
                [],
                ResponseInterface::HTTP_BAD_REQUEST
            );
        }

        $body   = $this->request->getJSON(true) ?? [];
        $reason = sanitize_string($body['reason'] ?? $body['cancellation_reason'] ?? 'Cancelled by requestor');

        $this->ticketModel->update($ticketId, [
            'status'         => 'cancelled',
            'status_label'   => 'Cancelled by Requestor',
            'decline_reason' => $reason,
            'is_archived'    => 1,
            'completed_at'   => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        $this->logModel->logAction(
            $ticketId,
            $userId,
            'Cancelled',
            "Ticket cancelled by requestor. Reason: {$reason}"
        );

        $this->notificationModel->createNotification(
            $ticket['user_id'],
            'info',
            "Ticket #{$ticketId} Cancelled",
            "Your service request #{$ticketId} has been successfully cancelled."
        );

        return $this->successResponse('Ticket cancelled successfully.', [
            'ticket_id' => $ticketId,
            'status'    => 'cancelled'
        ]);
    }

    // -------------------------------------------------------------------------
    // Consolidated Multi-Service Intake
    // -------------------------------------------------------------------------

    /**
     * Submit a consolidated service request from the ServicesListView/FormsView flow.
     *
     * Accepts a multi-unit payload:
     * {
     *   fgmu: { services: [...], details: {...} },
     *   leau: { services: [...], details: {...} },
     *   ssu:  { incidentReport: {...} },
     *   others: { description: "..." }
     * }
     *
     * All inserts are wrapped in a single DB transaction.
     */
    public function submitIntake(): ResponseInterface
    {
        $body   = $this->request->getJSON(true) ?? [];
        $userId = $this->currentUserId();

        $userModel = new \App\Models\UserModel();
        $user = $userModel->find($userId);
        if (!$user || empty($user['is_verified']) || (int)$user['is_verified'] !== 1) {
            return $this->errorResponse('Your account is pending verification by the Super Administrator before you can submit service requests.', [], ResponseInterface::HTTP_FORBIDDEN);
        }

        if ($user['status'] === 'Deactivated') {
            return $this->errorResponse(
                'Your account has been deactivated. You can view existing tickets, but you cannot submit new requests. Please contact the GSO office to reactivate your account.',
                ['is_deactivated' => true],
                ResponseInterface::HTTP_FORBIDDEN
            );
        }

        if ($user['status'] === 'Suspended') {
            return $this->errorResponse(
                'Your account has been suspended. Please contact the GSO office.',
                ['is_suspended' => true],
                ResponseInterface::HTTP_FORBIDDEN
            );
        }

        if (empty($body)) {
            return $this->errorResponse('Request body is empty.');
        }

        // Student Account Role Authorization Guard (RSO / SSG accounts)
        if ($user['role'] === 'student') {
            // 1. Block FGMU completely for students
            if (!empty($body['fgmu']['services'])) {
                return $this->errorResponse(
                    'Student accounts are not authorized to submit Facilities Management (FGMU) service requests. These requests must be submitted by university faculty or staff.',
                    ['unauthorized_unit' => 'FGMU'],
                    ResponseInterface::HTTP_FORBIDDEN
                );
            }

            // 2. Validate LEAU services: only allowed 4 services
            if (!empty($body['leau']['services'])) {
                $allowedLeauServices = [
                    'borrowing of tools/ equipment',
                    'borrowing of tools/equipment',
                    'borrowing of plants',
                    'hauling',
                    'stage & hall decoration',
                    'stage and hall decoration',
                ];

                foreach ($body['leau']['services'] as $srv) {
                    $rawService = trim((string)($srv['service'] ?? ''));
                    $normalizedService = strtolower(preg_replace('/\s+/', ' ', $rawService));
                    if (!in_array($normalizedService, $allowedLeauServices, true)) {
                        return $this->errorResponse(
                            "Student organization accounts (RSO/SSG) are not authorized to request '{$rawService}'. Authorized services are: Borrowing of Tools/Equipment, Borrowing of Plants, Hauling, and Stage & Hall Decoration.",
                            ['unauthorized_service' => $rawService],
                            ResponseInterface::HTTP_FORBIDDEN
                        );
                    }
                }
            }
        }

        $db             = Database::connect();
        $createdTickets = [];

        $db->transStart();

        try {
            // --- 1. FGMU Intake ---
            if (!empty($body['fgmu']['services'])) {
                $fgmuDetails = sanitize_array($body['fgmu']['details'] ?? []);
                $fgmuModel   = new FgmuTicketDetailModel();

                $servicesList = array_map(function($srv) {
                    return sanitize_string($srv['service'] ?? '');
                }, $body['fgmu']['services']);
                $servicesList = array_filter($servicesList);

                if (!empty($servicesList)) {
                    $serviceString = $this->formatServicesList($servicesList);
                    if (empty($serviceString)) {
                        $serviceString = 'Facilities Maintenance';
                    }

                    $ticketId = $this->ticketModel->generateTicketId('FGMU', self::UNIT_MAP['FGMU'], 0);

                    $this->ticketModel->insert([
                        'id'          => $ticketId,
                        'user_id'     => $userId,
                        'unit_id'     => self::UNIT_MAP['FGMU'],
                        'title'       => sanitize_string($fgmuDetails['ticket_title'] ?? $serviceString),
                        'service_type'=> $serviceString,
                        'description' => sanitize_string($fgmuDetails['job_description'] ?? 'Facilities maintenance request.'),
                        'status'      => 'pending',
                        'status_label'=> 'Pending Approval',
                        'is_emergency'=> 0,
                        'current_step'=> 2,
                        'location'    => sanitize_string($fgmuDetails['college_building'] ?? ''),
                        'office_room' => sanitize_string($fgmuDetails['office_room'] ?? ''),
                        'is_archived' => 0,
                        'submitted_at'=> date('Y-m-d H:i:s'),
                        'updated_at'  => date('Y-m-d H:i:s'),
                    ]);

                    $fgmuModel->insert([
                        'ticket_id'       => $ticketId,
                        'college_building'=> sanitize_string($fgmuDetails['college_building'] ?? ''),
                        'office_room'     => sanitize_string($fgmuDetails['office_room'] ?? ''),
                        'source_of_fund'  => sanitize_string($fgmuDetails['source_of_fund'] ?? ''),
                    ]);

                    $this->logModel->logAction($ticketId, $userId, 'Ticket Submitted', "FGMU service request: {$serviceString}");
                    $createdTickets[] = $ticketId;
                }
            }

            // --- 2. LEAU Intake ---
            if (!empty($body['leau']['services'])) {
                $leauDetails = sanitize_array($body['leau']['details'] ?? []);
                $leauModel   = new LeauTicketDetailModel();

                $servicesList = array_map(function($srv) {
                    return sanitize_string($srv['service'] ?? '');
                }, $body['leau']['services']);
                $servicesList = array_filter($servicesList);

                if (!empty($servicesList)) {
                    $serviceString = $this->formatServicesList($servicesList);
                    if (empty($serviceString)) {
                        $serviceString = 'Janitorial & Landscaping';
                    }

                    $ticketId = $this->ticketModel->generateTicketId('LEAU', self::UNIT_MAP['LEAU'], 0);

                    $this->ticketModel->insert([
                        'id'          => $ticketId,
                        'user_id'     => $userId,
                        'unit_id'     => self::UNIT_MAP['LEAU'],
                        'title'       => sanitize_string($leauDetails['ticket_title'] ?? $serviceString),
                        'service_type'=> $serviceString,
                        'description' => sanitize_string($leauDetails['job_description'] ?? 'Grounds maintenance request.'),
                        'status'      => 'pending',
                        'status_label'=> 'Pending Approval',
                        'is_emergency'=> 0,
                        'current_step'=> 2,
                        'location'    => sanitize_string($leauDetails['college_building'] ?? ''),
                        'office_room' => sanitize_string($leauDetails['office_room'] ?? ''),
                        'is_archived' => 0,
                        'submitted_at'=> date('Y-m-d H:i:s'),
                        'updated_at'  => date('Y-m-d H:i:s'),
                    ]);

                    $leauModel->insert([
                        'ticket_id'       => $ticketId,
                        'college_building'=> sanitize_string($leauDetails['college_building'] ?? ''),
                        'office_room'     => sanitize_string($leauDetails['office_room'] ?? ''),
                        'source_of_fund'  => sanitize_string($leauDetails['source_of_fund'] ?? ''),
                    ]);

                    $this->logModel->logAction($ticketId, $userId, 'Ticket Submitted', "LEAU service request: {$serviceString}");
                    $createdTickets[] = $ticketId;
                }
            }

            // --- 3. SSU Incident Report ---
            if (!empty($body['ssu']['incidentReport'])) {
                $inc      = sanitize_array($body['ssu']['incidentReport']);
                $ticketId = $this->ticketModel->generateTicketId('SSU', self::UNIT_MAP['SSU']);
                $typeStr  = is_array($inc['incidents'] ?? null) ? implode(', ', $inc['incidents']) : ($inc['otherIncident'] ?? 'Incident');

                $this->ticketModel->insert([
                    'id'                    => $ticketId,
                    'user_id'               => $userId,
                    'unit_id'               => self::UNIT_MAP['SSU'],
                    'title'                 => 'Incident Report',
                    'service_type'          => 'Incident Report',
                    'description'           => sanitize_string($inc['how'] ?? 'Incident reported to campus security.'),
                    'status'                => 'pending',
                    'status_label'          => 'Pending Review',
                    'is_emergency'          => 0,
                    'current_step'          => 2,
                    'is_under_investigation'=> 0,
                    'location'              => sanitize_string($inc['where'] ?? ''),
                    'is_archived'           => 0,
                    'submitted_at'          => date('Y-m-d H:i:s'),
                    'updated_at'            => date('Y-m-d H:i:s'),
                ]);

                (new SsuIncidentDetailModel())->insert([
                    'ticket_id'        => $ticketId,
                    'other_incident'   => sanitize_string($inc['otherIncident'] ?? ''),
                    'other_information'=> sanitize_string($inc['otherInformation'] ?? ''),
                    'follow_up'        => (bool) ($inc['followUp'] ?? false),
                    'who_involved'     => sanitize_string($inc['who'] ?? ''),
                    'where_occurred'   => sanitize_string($inc['where'] ?? ''),
                    'when_occurred'    => sanitize_string($inc['when'] ?? ''),
                    'how_narrative'    => sanitize_string($inc['how'] ?? ''),
                    'reporter_name'    => sanitize_string($inc['reportedBy']['printedName'] ?? ''),
                    'reporter_signature'=> $inc['reportedBy']['signature'] ?? null,
                ]);

                // Bridge table inserts for incident types, issues, and roles (3NF normalization)
                if (!empty($inc['incidents']) && is_array($inc['incidents'])) {
                    foreach ($inc['incidents'] as $type) {
                        $cleanType = sanitize_string($type);
                        $db->query("INSERT IGNORE INTO ssu_incident_types (type_name) VALUES (?)", [$cleanType]);
                        $row = $db->query("SELECT id FROM ssu_incident_types WHERE type_name = ?", [$cleanType])->getRowArray();
                        if ($row) {
                            $db->query("INSERT IGNORE INTO ssu_incident_type_items (ticket_id, incident_type_id) VALUES (?, ?)", [$ticketId, $row['id']]);
                        }
                    }
                }

                if (!empty($inc['information']) && is_array($inc['information'])) {
                    foreach ($inc['information'] as $info) {
                        $cleanInfo = sanitize_string($info);
                        $db->query("INSERT IGNORE INTO ssu_incident_issues (issue_name) VALUES (?)", [$cleanInfo]);
                        $row = $db->query("SELECT id FROM ssu_incident_issues WHERE issue_name = ?", [$cleanInfo])->getRowArray();
                        if ($row) {
                            $db->query("INSERT IGNORE INTO ssu_incident_issue_items (ticket_id, issue_id) VALUES (?, ?)", [$ticketId, $row['id']]);
                        }
                    }
                }

                $roles = $inc['reportedBy']['roles'] ?? [];
                if (!empty($roles) && is_array($roles)) {
                    foreach ($roles as $role) {
                        $cleanRole = sanitize_string($role);
                        $db->query("INSERT IGNORE INTO ssu_incident_roles (role_name) VALUES (?)", [$cleanRole]);
                        $row = $db->query("SELECT id FROM ssu_incident_roles WHERE role_name = ?", [$cleanRole])->getRowArray();
                        if ($row) {
                            $db->query("INSERT IGNORE INTO ssu_incident_role_items (ticket_id, role_id) VALUES (?, ?)", [$ticketId, $row['id']]);
                        }
                    }
                }

                $this->logModel->logAction($ticketId, $userId, 'Ticket Submitted', "SSU Incident Report: {$typeStr}");
                $createdTickets[] = $ticketId;
            }



        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', '[TicketController::submitIntake] DB Error: ' . $e->getMessage());

            return $this->errorResponse(
                'An error occurred while submitting your request. Please try again.',
                [],
                ResponseInterface::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->errorResponse(
                'Transaction failed. Please try again.',
                [],
                ResponseInterface::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        // --- Notify Admins ---
        foreach ($createdTickets as $tId) {
            $t = $this->ticketModel->find($tId);
            if ($t) {
                $admins = $db->query("SELECT id FROM users WHERE role = 'admin' AND unit_id = ?", [$t['unit_id']])->getResultArray();
                foreach($admins as $admin) {
                    $this->notificationModel->createNotification(
                        $admin['id'], 
                        'info', 
                        "New Ticket Submitted", 
                        "Ticket #{$tId} for {$t['service_type']} requires review."
                    );
                }
            }
        }

        return $this->successResponse(
            count($createdTickets) . ' ticket(s) submitted successfully.',
            ['ticket_ids' => $createdTickets],
            ResponseInterface::HTTP_CREATED
        );
    }

    // -------------------------------------------------------------------------
    // Scheduled Projects (FGMU & LEAU Announcements)
    // -------------------------------------------------------------------------

    public function createProject(): ResponseInterface
    {
        $userId = $this->currentUserId();
        $role   = $this->currentUserRole();
        // Only allow admins or superadmins to create project announcements
        if (!in_array($role, ['admin', 'superadmin'], true)) {
            return $this->errorResponse('Unauthorized to create project announcements. Only Unit Admins may post projects.', [], ResponseInterface::HTTP_FORBIDDEN);
        }

        $body = $this->request->getJSON(true) ?? [];
        $unitCode = strtoupper(sanitize_string($body['unit'] ?? ''));
        $unitId = self::UNIT_MAP[$unitCode] ?? null;

        if (!$unitId || !in_array($unitCode, ['FGMU', 'LEAU'])) {
            return $this->errorResponse('Projects can only be created for FGMU or LEAU.');
        }

        if ($forbidden = $this->assertUnitAccess($unitId)) {
            return $forbidden;
        }

        $title = sanitize_string($body['title'] ?? '');
        $description = sanitize_string($body['description'] ?? '');
        $location = sanitize_string($body['location'] ?? '');
        $duration = sanitize_string($body['duration'] ?? ''); // Duration e.g. "5 Working Days" or "5"
        $targetDate = sanitize_string($body['target_date'] ?? null);
        $targetDate = empty($targetDate) ? null : $targetDate;
        $remarks = sanitize_string($body['remarks'] ?? '');

        if (empty($title)) {
            return $this->errorResponse('Project title is required.');
        }

        $db = Database::connect();
        $db->transStart();

        try {
            // Generate dedicated Project ID with -PRJ- prefix (does not consume ticket -TIC- sequence)
            $projectId = $this->ticketModel->generateProjectId($unitCode, $unitId);

            $this->ticketModel->insert([
                'id'                      => $projectId,
                'user_id'                 => $userId, // The admin who created it
                'unit_id'                 => $unitId,
                'service_type'            => 'Office Project',
                'description'             => !empty($description) ? $description : $title,
                'status'                  => 'approved', // Auto-approve
                'status_label'            => 'Approved',
                'current_step'            => 2,
                'location'                => $location,
                'is_project'              => 1,
                'project_title'           => $title,
                'project_target_duration' => $duration,
                'project_target_date'     => $targetDate,
                'project_manpower'        => null,
                'project_remarks'         => $remarks,
                'submitted_at'            => date('Y-m-d H:i:s'),
                'reviewed_at'             => date('Y-m-d H:i:s'),
                'reviewed_by'             => $userId,
                'updated_at'              => date('Y-m-d H:i:s'),
            ]);

            // Also create the corresponding detail record
            if ($unitCode === 'FGMU') {
                (new FgmuTicketDetailModel())->insert([
                    'ticket_id'        => $projectId,
                    'college_building' => $location,
                    'office_room'      => '',
                ]);
            } else if ($unitCode === 'LEAU') {
                (new LeauTicketDetailModel())->insert([
                    'ticket_id'        => $projectId,
                    'college_building' => $location,
                    'office_room'      => '',
                ]);
            }

            $this->logModel->logAction($projectId, $userId, 'Project Created', "Scheduled project announcement created.");

        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', '[TicketController::createProject] ' . $e->getMessage());
            return $this->errorResponse('Failed to create project.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->errorResponse('Transaction failed.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->successResponse('Project announcement created successfully.', ['ticket_id' => $projectId, 'id' => $projectId], ResponseInterface::HTTP_CREATED);
    }

    public function getProjects(): ResponseInterface
    {
        $tickets = $this->ticketModel->where('is_project', 1)
                                     ->whereIn('status', ['pending', 'approved', 'processing'])
                                     ->orderBy('submitted_at', 'DESC')
                                     ->findAll();
        
        $tickets = $this->enrichTickets($tickets);
        return $this->successResponse('Active projects retrieved.', ['projects' => $tickets]);
    }

    public function getProjectArchives(): ResponseInterface
    {
        $tickets = $this->ticketModel->where('is_project', 1)
                                     ->whereIn('status', ['resolved', 'closed', 'completed'])
                                     ->orderBy('completed_at', 'DESC')
                                     ->findAll();
        
        $tickets = $this->enrichTickets($tickets);
        return $this->successResponse('Archived projects retrieved.', ['projects' => $tickets]);
    }

    public function updateProject(string $ticketId): ResponseInterface
    {
        $userId = $this->currentUserId();
        $role   = $this->currentUserRole();
        // Only allow admins or superadmins to update project announcements
        if (!in_array($role, ['admin', 'superadmin'], true)) {
            return $this->errorResponse('Unauthorized to update project announcements. Only Unit Admins may manage projects.', [], ResponseInterface::HTTP_FORBIDDEN);
        }

        $ticket = $this->ticketModel->find($ticketId);
        if (!$ticket || !(bool)$ticket['is_project']) {
            return $this->notFoundResponse('Project');
        }

        if ($forbidden = $this->assertUnitAccess((int) $ticket['unit_id'])) {
            return $forbidden;
        }

        $body = $this->request->getJSON(true) ?? [];
        
        $updateData = ['updated_at' => date('Y-m-d H:i:s')];
        if (array_key_exists('actual_start', $body)) {
            $val = sanitize_string($body['actual_start'] ?? null);
            $updateData['project_actual_start'] = empty($val) ? null : $val;
        }
        if (array_key_exists('actual_completion', $body)) {
            $val = sanitize_string($body['actual_completion'] ?? null);
            $updateData['project_actual_completion'] = empty($val) ? null : $val;
        }
        if (array_key_exists('actual_working_days', $body)) {
            $updateData['project_working_days'] = !empty($body['actual_working_days']) ? (int) $body['actual_working_days'] : null;
        }
        if (array_key_exists('remarks', $body)) {
            $updateData['project_remarks'] = sanitize_string($body['remarks'] ?? '');
        }

        $this->ticketModel->update($ticketId, $updateData);
        $this->logModel->logAction($ticketId, $userId, 'Project Updated', "Project details were updated.");

        return $this->successResponse('Project updated successfully.', ['ticket_id' => $ticketId]);
    }

}
