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
 * TicketAttachmentController - ticket file handling.
 *
 * Methods moved verbatim from TicketController (bodies untouched):
 *  uploadAttachment, downloadAttachment, uploadAccomplishment,
 *  downloadAccomplishment.
 */
class TicketAttachmentController extends BaseController
{
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
    // Attachments (Upload & Download)
    // -------------------------------------------------------------------------

    /**
     * Upload one or more attachments to a specific ticket.
     */
    public function uploadAttachment(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);

        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        // Security check: only ticket owner or admins can upload
        $userId = $this->currentUserId();
        $role = $this->currentUserRole();
        if ((string) $ticket['user_id'] !== (string) $userId && !in_array($role, ['admin', 'director', 'superadmin'], true)) {
            return $this->forbiddenResponse('You do not have permission to upload files to this ticket.');
        }

        $rawAttachments = $this->request->getFileMultiple('attachments');
        if (empty($rawAttachments)) {
            $files = $this->request->getFiles();
            $rawAttachments = $files['attachments'] ?? null;
        }

        if (empty($rawAttachments)) {
            $single = $this->request->getFile('attachment') ?? $this->request->getFile('attachments');
            if ($single) {
                $rawAttachments = [$single];
            }
        }

        if (!is_array($rawAttachments)) {
            $rawAttachments = $rawAttachments ? [$rawAttachments] : [];
        }

        $attachments = array_values(array_filter($rawAttachments, fn($f) => ($f instanceof \CodeIgniter\HTTP\Files\UploadedFile) && $f->getError() !== UPLOAD_ERR_NO_FILE));

        if (empty($attachments)) {
            log_message('error', '[TicketAttachmentController::uploadAttachment] No files received for ticket ' . $ticketId . '. Files keys: ' . implode(',', array_keys($this->request->getFiles())));
            return $this->errorResponse('No files uploaded. Use "attachments[]" key in your form-data.');
        }

        $attachmentModel = new TicketAttachmentModel();
        $uploadedData = [];
        $errors = [];

        // Determine year from created_at or fallback to current year
        $year = date('Y', strtotime($ticket['created_at'] ?? date('Y-m-d')));
        $uploadPath = WRITEPATH . "uploads/tickets/{$year}/{$ticketId}/";

        // Ensure upload directory exists
        if (!is_dir($uploadPath)) {
            @mkdir($uploadPath, 0775, true);
        }

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
        $allowedMimes = [
            'image/jpeg', 'image/png', 'image/jpg', 'image/webp',
            'application/pdf', 
            'application/msword', 
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/x-zip',
            'application/x-zip-compressed',
            'application/octet-stream',
            'application/x-download',
            'binary/octet-stream'
        ];

        foreach ($attachments as $file) {
            $clientName = $file->getClientName();
            $ext = strtolower($file->getClientExtension());

            if ($file->isValid() && !$file->hasMoved()) {
                // Validate size (5MB max)
                $sizeMb = $file->getSizeByUnit('mb');
                if ($sizeMb > 5) {
                    $errors[] = $clientName . ' exceeds the 5MB size limit.';
                    continue;
                }

                // Validate mime type & extension
                $mime = $file->getMimeType();
                $clientMime = $file->getClientMimeType();

                if (!in_array($ext, $allowedExtensions)) {
                    $errors[] = $clientName . ' has an unsupported file extension (.' . $ext . ').';
                    continue;
                }

                if (!in_array($mime, $allowedMimes) && !in_array($clientMime, $allowedMimes)) {
                    $errors[] = $clientName . ' has an invalid file type (' . $mime . ').';
                    continue;
                }

                // Normalize stored MIME type based on verified extension
                if ($ext === 'docx') {
                    $storedMime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                } elseif ($ext === 'doc') {
                    $storedMime = 'application/msword';
                } elseif ($ext === 'pdf') {
                    $storedMime = 'application/pdf';
                } elseif ($ext === 'xlsx') {
                    $storedMime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                } elseif ($ext === 'xls') {
                    $storedMime = 'application/vnd.ms-excel';
                } else {
                    $storedMime = $mime;
                }

                $securityService = new \App\Libraries\FileSecurityService();
                $inspection = $securityService->inspectFile($file->getTempName(), $clientName, $mime);
                if (!$inspection['safe']) {
                    $errors[] = $clientName . ' rejected: ' . $inspection['reason'];
                    continue;
                }

                $newName = $file->getRandomName();
                $fileSize = $file->getSize();

                if ($file->move($uploadPath, $newName)) {
                    $savedFullPath = $uploadPath . $newName;

                    // Encrypt document at rest using AES-256-GCM
                    $isEncrypted   = 0;
                    $encryptionIv  = null;
                    $encryptionTag = null;
                    try {
                        $encResult = $securityService->encryptFile($savedFullPath, $savedFullPath);
                        $isEncrypted   = 1;
                        $encryptionIv  = $encResult['iv'];
                        $encryptionTag = $encResult['tag'];
                    } catch (\Throwable $e) {
                        log_message('error', 'Attachment encryption error: ' . $e->getMessage());
                    }

                    $record = [
                        'ticket_id'       => $ticketId,
                        'file_name'       => $clientName,
                        'file_path'       => "tickets/{$year}/{$ticketId}/{$newName}",
                        'file_type'       => $storedMime,
                        'file_size_bytes' => $fileSize,
                        'is_encrypted'    => $isEncrypted,
                        'encryption_iv'   => $encryptionIv,
                        'encryption_tag'  => $encryptionTag,
                        'uploaded_at'     => date('Y-m-d H:i:s'),
                    ];
                    
                    // If uploading a Job Order document (initial or re-generated), remove any older Job Order attachments to prevent duplicates
                    if (stripos($clientName, 'Job_Order_#') !== false || stripos($clientName, 'Job Request Form') !== false) {
                        try {
                            $oldJobOrders = $attachmentModel->where('ticket_id', $ticketId)
                                ->groupStart()
                                    ->like('file_name', 'Job_Order_#')
                                    ->orLike('file_name', 'Job Request Form')
                                ->groupEnd()
                                ->findAll();

                            foreach ($oldJobOrders as $oldAtt) {
                                $oldFilePath = WRITEPATH . 'uploads/' . $oldAtt['file_path'];
                                if (file_exists($oldFilePath)) {
                                    @unlink($oldFilePath);
                                }
                                $attachmentModel->delete($oldAtt['id']);
                            }
                        } catch (\Throwable $cleanErr) {
                            log_message('warning', 'Could not cleanup old job order attachments: ' . $cleanErr->getMessage());
                        }
                    }

                    $attachmentModel->insert($record);
                    $record['id'] = $attachmentModel->getInsertID();
                    $uploadedData[] = $record;
                } else {
                    $errors[] = "Failed to save " . $clientName;
                }
            } else {
                if ($file->getError() !== UPLOAD_ERR_NO_FILE) {
                    $errors[] = "Error uploading " . $clientName . " - " . $file->getErrorString();
                }
            }
        }

        if (empty($uploadedData) && !empty($errors)) {
            log_message('error', '[TicketAttachmentController::uploadAttachment] Upload failed for ticket ' . $ticketId . ': ' . implode(' | ', $errors));
            return $this->errorResponse('File upload failed.', $errors);
        }

        return $this->successResponse('Files uploaded successfully.', [
            'attachments' => $uploadedData,
            'errors'      => $errors
        ]);
    }

    /**
     * Download or view an attachment securely with AES-256 decryption.
     */
    public function downloadAttachment(int $attachmentId)
    {
        $attachmentModel = new TicketAttachmentModel();
        $attachment = $attachmentModel->find($attachmentId);

        if (!$attachment) {
            return $this->response->setStatusCode(404)->setBody('Attachment not found.');
        }

        $ticket = $this->ticketModel->find($attachment['ticket_id']);
        if (!$ticket) {
            return $this->response->setStatusCode(404)->setBody('Associated ticket not found.');
        }

        // Security check
        $userId = $this->currentUserId();
        $role = $this->currentUserRole();
        if ((string) $ticket['user_id'] !== (string) $userId && !in_array($role, ['admin', 'director', 'superadmin'], true)) {
            return $this->response->setStatusCode(403)->setBody('Forbidden.');
        }

        $fullPath = WRITEPATH . 'uploads/' . $attachment['file_path'];

        if (!is_file($fullPath)) {
            return $this->response->setStatusCode(404)->setBody('File not found on server.');
        }

        // If file was encrypted at rest, decrypt on-the-fly
        if (!empty($attachment['is_encrypted']) && !empty($attachment['encryption_iv']) && !empty($attachment['encryption_tag'])) {
            $securityService = new \App\Libraries\FileSecurityService();
            $decrypted = $securityService->decryptFile($fullPath, $attachment['encryption_iv'], $attachment['encryption_tag']);
            if ($decrypted === null) {
                return $this->response->setStatusCode(500)->setBody('Integrity check failed: unable to decrypt attachment.');
            }

            return $this->response
                ->setHeader('Content-Type', $attachment['file_type'] ?: 'application/octet-stream')
                ->setHeader('Content-Disposition', 'attachment; filename="' . rawurlencode($attachment['file_name']) . '"')
                ->setBody($decrypted);
        }

        return $this->response->download($fullPath, null)->setFileName($attachment['file_name']);
    }

    /**
     * Upload Accomplishment Report for a ticket.
     * Mandatory proof of completion before a ticket can be closed.
     */
    public function uploadAccomplishment(string $ticketId): ResponseInterface
    {
        $ticket = $this->ticketModel->find($ticketId);
        if (!$ticket) {
            return $this->notFoundResponse('Ticket');
        }

        $file = $this->request->getFile('accomplishment_report');
        if (!$file || !$file->isValid() || $file->hasMoved()) {
            return $this->errorResponse('Accomplishment report file is required ("accomplishment_report").');
        }

        $securityService = new \App\Libraries\FileSecurityService();
        $inspection = $securityService->inspectFile($file->getTempName(), $file->getClientName(), $file->getClientMimeType());
        if (!$inspection['safe']) {
            return $this->errorResponse('Security violation: ' . $inspection['reason'], [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $year = date('Y');
        $uploadPath = WRITEPATH . "uploads/accomplishments/{$year}/{$ticketId}/";
        if (!is_dir($uploadPath)) {
            @mkdir($uploadPath, 0775, true);
        }

        $newName = $file->getRandomName();
        if (!$file->move($uploadPath, $newName)) {
            return $this->errorResponse('Failed to store accomplishment report.');
        }

        $savedFullPath = $uploadPath . $newName;
        try {
            $securityService->encryptSelfContained($savedFullPath, $savedFullPath);
        } catch (\Throwable $e) {
            log_message('error', 'Failed to encrypt accomplishment report: ' . $e->getMessage());
        }

        $reportRelPath = "accomplishments/{$year}/{$ticketId}/{$newName}";
        $notes = sanitize_string($this->request->getPost('notes') ?? '');

        $this->ticketModel->update($ticketId, [
            'accomplishment_report_path' => $reportRelPath,
            'accomplishment_notes'       => $notes,
            'verification_status'        => 'pending_verification',
            'status'                     => 'resolved',
            'status_label'               => 'Resolved (Pending Verification)',
            'updated_at'                 => date('Y-m-d H:i:s'),
        ]);

        $this->logModel->logAction(
            $ticketId,
            $this->currentUserId(),
            'Accomplishment Report Uploaded',
            "Accomplishment report uploaded: {$file->getClientName()}. Awaiting verification and ticket closure."
        );

        $notificationModel = new \App\Models\NotificationModel();
        $notificationModel->createNotification(
            $ticket['user_id'],
            'info',
            "Ticket #{$ticketId} Accomplishment Submitted",
            "Field services have been completed with accomplishment report. Verification in progress."
        );

        return $this->successResponse('Accomplishment report submitted successfully. Ticket is pending verification.', [
            'verification_status' => 'pending_verification',
            'report_path'         => $reportRelPath,
        ]);
    }

    /**
     * Download or preview accomplishment report securely with AES-256 decryption.
     */
    public function downloadAccomplishment(string $ticketId)
    {
        $ticket = $this->ticketModel->find($ticketId);
        if (!$ticket || empty($ticket['accomplishment_report_path'])) {
            return $this->response->setStatusCode(404)->setBody('Accomplishment report not found.');
        }

        $userId = $this->currentUserId();
        $role   = $this->currentUserRole();
        if ((string)$ticket['user_id'] !== (string)$userId && !in_array($role, ['admin', 'director', 'superadmin'], true)) {
            return $this->response->setStatusCode(403)->setBody('Forbidden.');
        }

        $fullPath = WRITEPATH . 'uploads/' . $ticket['accomplishment_report_path'];
        if (!is_file($fullPath)) {
            return $this->response->setStatusCode(404)->setBody('File not found on server.');
        }

        $securityService = new \App\Libraries\FileSecurityService();
        $decrypted = $securityService->decryptSelfContained($fullPath);
        if ($decrypted === null) {
            return $this->response->setStatusCode(500)->setBody('Failed to decrypt accomplishment report.');
        }

        $ext  = strtolower(pathinfo($ticket['accomplishment_report_path'], PATHINFO_EXTENSION));
        $mime = match($ext) {
            'pdf'          => 'application/pdf',
            'png'          => 'image/png',
            'jpg', 'jpeg'  => 'image/jpeg',
            'webp'         => 'image/webp',
            default        => 'application/octet-stream',
        };

        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('Content-Disposition', 'inline; filename="Accomplishment_Report_' . $ticketId . '.' . $ext . '"')
            ->setBody($decrypted);
    }

}
