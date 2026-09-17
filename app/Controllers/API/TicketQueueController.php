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
 * TicketQueueController - read-only queues & single-ticket views.
 *
 * Methods moved verbatim from TicketController (bodies untouched):
 *  pendingQueue, delayedApprovalQueue, dispatchQueue, activeTickets, archives,
 *  investigatingQueue, unitStats, show, logs.
 * Shared enrichment via Concerns\TicketEnrichmentTrait.
 */
class TicketQueueController extends BaseController
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

    /**
     * Parse ?page=&per_page= query params (Performance Efficiency: bounded
     * result sets keep response time stable under concurrent load).
     * Defaults (page 1, 100/queue, 200/archives) preserve existing client
     * behavior at realistic GSO volumes; pass ?per_page= to override.
     *
     * @return array{page:int, per_page:int, limit:int, offset:int}
     */
    private function paginationParams(int $defaultPerPage = 100): array
    {
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = (int) ($this->request->getGet('per_page') ?? $defaultPerPage);
        $perPage = min(500, max(1, $perPage));

        return [
            'page'     => $page,
            'per_page' => $perPage,
            'limit'    => $perPage,
            'offset'   => ($page - 1) * $perPage,
        ];
    }

    // -------------------------------------------------------------------------
    // Admin Queues
    // -------------------------------------------------------------------------

    /**
     * Get the pending ticket queue for a given unit.
     */
    public function pendingQueue(string $unitCode): ResponseInterface
    {
        if ($forbidden = $this->assertUnitAccess($unitCode)) {
            return $forbidden;
        }

        $unitId = $this->resolveUnitId($unitCode);
        $pg      = $this->paginationParams();
        $tickets = $this->ticketModel->getPendingQueue($unitId, $pg['limit'], $pg['offset']);
        $tickets = $this->enrichTickets($tickets);

        return $this->successResponse('Pending queue retrieved.', ['tickets' => $tickets, 'count' => count($tickets), 'page' => $pg['page'], 'per_page' => $pg['per_page']]);
    }

    /**
     * Get tickets delayed for approval (e.g., awaiting procurement of materials) for a given unit.
     */
    public function delayedApprovalQueue(string $unitCode): ResponseInterface
    {
        if ($forbidden = $this->assertUnitAccess($unitCode)) {
            return $forbidden;
        }

        $unitId = $this->resolveUnitId($unitCode);
        $pg      = $this->paginationParams();
        $tickets = $this->ticketModel->getApprovalDelayedQueue($unitId, $pg['limit'], $pg['offset']);
        $tickets = $this->enrichTickets($tickets);

        return $this->successResponse('Delayed approval queue retrieved.', ['tickets' => $tickets, 'count' => count($tickets), 'page' => $pg['page'], 'per_page' => $pg['per_page']]);
    }

    /**
     * Get approved tickets awaiting dispatch for a given unit.
     */
    public function dispatchQueue(string $unitCode): ResponseInterface
    {
        if ($forbidden = $this->assertUnitAccess($unitCode)) {
            return $forbidden;
        }

        $unitId = $this->resolveUnitId($unitCode);
        $pg      = $this->paginationParams();
        $tickets = $this->ticketModel->getDispatchQueue($unitId, $pg['limit'], $pg['offset']);
        $tickets = $this->enrichTickets($tickets);

        return $this->successResponse('Dispatch queue retrieved.', ['tickets' => $tickets, 'count' => count($tickets), 'page' => $pg['page'], 'per_page' => $pg['per_page']]);
    }

    /**
     * Get in-progress tickets for a unit (auto-starts tickets scheduled for today).
     */
    public function activeTickets(string $unitCode): ResponseInterface
    {
        if ($forbidden = $this->assertUnitAccess($unitCode)) {
            return $forbidden;
        }

        $unitId = $this->resolveUnitId($unitCode);
        $pg      = $this->paginationParams();
        $tickets = $this->ticketModel->getActiveTickets($unitId, $pg['limit'], $pg['offset']);
        $tickets = $this->enrichTickets($tickets);

        $today = date('Y-m-d');

        $personnelModel = new \App\Models\PersonnelModel();
        $assignmentModel = new \App\Models\TicketAssignmentModel();
        $logModel = new \App\Models\TicketLogModel();

        foreach ($tickets as &$ticket) {
            $currentStep = (int) $ticket['current_step'];
            $needsStart = ($currentStep === 4);
            
            if ($needsStart && !empty($ticket['assignment']['implementation_date'])) {
                if ($ticket['assignment']['implementation_date'] <= $today) {
                    $workerStatus = 'working';
                    $statusLabel  = 'Job Started';
                    $newStep      = 5;
                    $now          = date('Y-m-d H:i:s');

                    $this->ticketModel->update($ticket['id'], [
                        'status_label' => $statusLabel,
                        'current_step' => $newStep,
                        'updated_at'   => $now,
                    ]);

                    $assignments = $assignmentModel->getByTicket($ticket['id']);
                    foreach ($assignments as $assignment) {
                        if (!empty($assignment['personnel_id'])) {
                            $personnelModel->update($assignment['personnel_id'], [
                                'status'     => $workerStatus,
                                'updated_at' => $now,
                            ]);
                        }
                        // Stamp dispatched_at if not yet set
                        if (empty($assignment['dispatched_at'])) {
                            $dispatchedTime = !empty($assignment['assigned_at']) ? $assignment['assigned_at'] : $now;
                            $assignmentModel->update($assignment['id'], [
                                'dispatched_at' => $dispatchedTime,
                            ]);
                            if (isset($ticket['assignment']['id']) && $ticket['assignment']['id'] == $assignment['id']) {
                                $ticket['assignment']['dispatched_at'] = $dispatchedTime;
                            }
                        }
                    }

                    $logModel->logAction($ticket['id'], $this->currentUserId(), 'Job Started', "System automatically started the job based on implementation date.");
                    
                    $ticket['current_step'] = $newStep;
                    $ticket['status_label'] = $statusLabel;
                }
            }
        }
        unset($ticket);

        return $this->successResponse('Active tickets retrieved.', ['tickets' => $tickets, 'count' => count($tickets), 'page' => $pg['page'], 'per_page' => $pg['per_page']]);
    }

    /**
     * Get archived tickets for a unit (with optional search/filter params).
     */
    public function archives(string $unitCode): ResponseInterface
    {
        if ($forbidden = $this->assertUnitAccess($unitCode)) {
            return $forbidden;
        }

        $unitId = $this->resolveUnitId($unitCode);
        $filters = [
            'search'    => sanitize_string($this->request->getGet('search') ?? ''),
            'status'    => sanitize_string($this->request->getGet('status') ?? ''),
            'date_from' => sanitize_string($this->request->getGet('date_from') ?? ''),
            'date_to'   => sanitize_string($this->request->getGet('date_to') ?? ''),
        ];

        $pg      = $this->paginationParams(200);
        $tickets = $this->ticketModel->getArchivedByUnit($unitId, $filters, $pg['limit'], $pg['offset']);
        $tickets = $this->enrichTickets($tickets);

        return $this->successResponse('Archived tickets retrieved.', ['tickets' => $tickets, 'count' => count($tickets), 'page' => $pg['page'], 'per_page' => $pg['per_page']]);
    }

    /**
     * Get unit dashboard stats (pending, processing, resolved counts).
     */
    public function unitStats(string $unitCode): ResponseInterface
    {
        $filters = [
            'period'  => $this->request->getGet('period') ?? 'all',
            'year'    => $this->request->getGet('year'),
            'quarter' => $this->request->getGet('quarter'),
            'month'   => $this->request->getGet('month'),
            'date'    => $this->request->getGet('date'),
        ];

        if (strtoupper($unitCode) === 'ALL') {
            if (!in_array($this->currentUserRole(), ['director', 'superadmin'], true)) {
                return $this->forbiddenResponse('Only the director or superadmin role can access global statistics.');
            }
            // Performance Efficiency: aggregate stats are cached 60s to keep
            // dashboard polling cheap under concurrent load.
            $cacheKey = 'unit_stats_all_' . md5(json_encode($filters));
            $stats    = cache()->remember($cacheKey, 60, function () use ($filters) {
                return $this->ticketModel->getAdvancedStatsByUnit(null, $filters);
            });
            return $this->successResponse('Global statistics retrieved.', ['stats' => $stats]);
        }

        if ($forbidden = $this->assertUnitAccess($unitCode)) {
            return $forbidden;
        }

        $unitId = self::UNIT_MAP[strtoupper($unitCode)] ?? null;

        if (!$unitId) {
            return $this->errorResponse("Unknown unit code: {$unitCode}.");
        }

        $cacheKey = 'unit_stats_' . $unitId . '_' . md5(json_encode($filters));
        $stats    = cache()->remember($cacheKey, 60, function () use ($unitId, $filters) {
            return $this->ticketModel->getAdvancedStatsByUnit($unitId, $filters);
        });
        return $this->successResponse('Unit statistics retrieved.', ['stats' => $stats]);
    }

    // -------------------------------------------------------------------------
    // Single Ticket
    // -------------------------------------------------------------------------

    /**
     * Get a single ticket with its unit-specific detail data and attachments.
     */
    public function show(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);

        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        // Authorization check: only ticket owner or staff roles can view ticket details
        $userId = $this->currentUserId();
        $role   = $this->currentUserRole();
        if ((string) $ticket['user_id'] !== (string) $userId && !$this->isStaffRole()) {
            return $this->forbiddenResponse('You do not have permission to view this ticket.');
        }

        // For staff roles, also ensure unit jurisdiction (director has campus-wide access)
        if ($this->isStaffRole() && $role === 'admin') {
            if ($forbidden = $this->assertUnitAccess((int) $ticket['unit_id'])) {
                return $forbidden;
            }
        }

        // Enrich with unit-specific details
        $enriched = $this->enrichTickets([$ticket]);
        $ticket   = $enriched[0];

        // Attachments
        $attachmentModel      = new TicketAttachmentModel();
        $ticket['attachments'] = $attachmentModel->getByTicket($ticketId);

        // Logs
        $ticket['logs'] = $this->logModel->getByTicket($ticketId);

        return $this->successResponse('Ticket retrieved.', ['ticket' => $ticket]);
    }

    /**
     * Fetch the Under Investigation queue for a unit (SSU-only in practice).
     *
     * GET /tickets/investigating/:unitCode
     */
    public function investigatingQueue(string $unitCode): ResponseInterface
    {
        if ($forbidden = $this->assertUnitAccess($unitCode)) {
            return $forbidden;
        }

        $unitId = $this->resolveUnitId($unitCode);
        $pg      = $this->paginationParams();
        $tickets = $this->ticketModel->getUnderInvestigationQueue($unitId, $pg['limit'], $pg['offset']);
        $tickets = $this->enrichTickets($tickets);

        return $this->successResponse(
            'Under investigation queue retrieved.',
            ['tickets' => $tickets, 'count' => count($tickets), 'page' => $pg['page'], 'per_page' => $pg['per_page']]
        );
    }

    /**
     * Get audit trail logs for a ticket.
     */
    public function logs(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);

        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        // Authorization check: only ticket owner or staff roles can view ticket audit logs
        $userId = $this->currentUserId();
        $role   = $this->currentUserRole();
        if ((string) $ticket['user_id'] !== (string) $userId && !$this->isStaffRole()) {
            return $this->forbiddenResponse('You do not have permission to view logs for this ticket.');
        }

        // For staff roles, also ensure unit jurisdiction (director has campus-wide access)
        if ($this->isStaffRole() && $role === 'admin') {
            if ($forbidden = $this->assertUnitAccess((int) $ticket['unit_id'])) {
                return $forbidden;
            }
        }

        $logs = $this->logModel->getByTicket($ticketId);

        return $this->successResponse('Ticket logs retrieved.', ['logs' => $logs]);
    }

}
