<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Libraries\FileSecurityService;
use App\Models\AccountActivityLogModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * UserProfileController
 *
 * Dedicated controller for authenticated user profile management,
 * credential security (password changes), digital signatures, and media assets.
 */
class UserProfileController extends BaseController
{
    private UserModel $userModel;
    private AccountActivityLogModel $activityLogModel;

    public function __construct()
    {
        $this->userModel        = new UserModel();
        $this->activityLogModel = new AccountActivityLogModel();
    }

    /**
     * Update the current user's profile fields (name, contact number, email).
     * Requires: JwtAuthFilter
     */
    public function updateProfile(): ResponseInterface
    {
        try {
            $userId = $this->currentUserId();
            $body   = $this->request->getJSON(true) ?? [];

            $currentUser = $this->userModel->find($userId);
            if (!$currentUser) {
                return $this->errorResponse('User not found.', [], ResponseInterface::HTTP_NOT_FOUND);
            }

            $isPersonnel = in_array(strtolower($currentUser['role'] ?? ''), ['worker', 'personnel'], true);
            $caps        = $this->userModel->getSchemaCapabilities();
            $updateData  = [];

            if (isset($body['first_name'])) {
                $updateData['first_name'] = sanitize_string($body['first_name']);
            }
            if (isset($body['last_name'])) {
                $updateData['last_name'] = sanitize_string($body['last_name']);
            }
            if (array_key_exists('name_extension', $body)) {
                $rawExt = trim((string) ($body['name_extension'] ?? ''));
                if (!empty($caps['has_name_extension'])) {
                    $updateData['name_extension'] = $rawExt !== '' ? sanitize_string($rawExt) : null;
                }
            }
            if (isset($body['contact_number'])) {
                $updateData['contact_number'] = sanitize_string($body['contact_number']);
            }
            if (array_key_exists('email_notifications_enabled', $body)) {
                $raw = $body['email_notifications_enabled'];
                $updateData['email_notifications_enabled'] = ($raw === true || $raw === 1 || $raw === '1' || $raw === 'true') ? 1 : 0;
            }
            if (array_key_exists('is_mfa_enabled', $body)) {
                $rawMfa  = $body['is_mfa_enabled'];
                $enabled = ($rawMfa === true || $rawMfa === 1 || $rawMfa === '1' || $rawMfa === 'true') ? 1 : 0;
                if ($enabled === 1) {
                    $targetEmail = $updateData['email'] ?? ($currentUser['email'] ?? '');
                    if (empty($targetEmail) || !filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
                        return $this->errorResponse(
                            'A valid email address is required to enable Two-Factor Authentication (MFA).',
                            ['is_mfa_enabled' => 'A valid email address is required to enable Two-Factor Authentication.'],
                            ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
                        );
                    }
                }
                $updateData['is_mfa_enabled'] = $enabled;
            }

            // Handle identifier: Username for personnel/field workers; institutional email for requestors & staff
            if ($isPersonnel) {
                $rawUsername = $body['username'] ?? $body['email'] ?? null;
                if ($rawUsername !== null) {
                    $username = trim(sanitize_string($rawUsername));
                    if ($username === '') {
                        return $this->errorResponse('Username cannot be empty.', ['username' => 'Username cannot be empty.'], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
                    }
                    if (mb_strlen($username) < 3 || mb_strlen($username) > 50 || !preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
                        return $this->errorResponse(
                            'Username must be 3-50 characters long and contain only letters, numbers, dots, hyphens, or underscores.',
                            ['username' => 'Username must be 3-50 characters long and contain only letters, numbers, dots, hyphens, or underscores.'],
                            ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
                        );
                    }

                    // Check uniqueness across users table (email, student_id_number, and username)
                    $checkQuery = $this->userModel->groupStart()
                        ->where('email', $username)
                        ->orWhere('student_id_number', $username);
                    if (!empty($caps['has_username'])) {
                        $checkQuery->orWhere('username', $username);
                    }
                    $existing = $checkQuery->groupEnd()->first();
                    if ($existing && (string) ($existing['id'] ?? '') !== (string) $userId) {
                        return $this->errorResponse(
                            'This username is already taken. Please choose another username.',
                            ['username' => 'This username is already taken. Please choose another username.'],
                            ResponseInterface::HTTP_CONFLICT
                        );
                    }

                    if (!empty($caps['has_username'])) {
                        $updateData['username'] = $username;
                    }
                    $updateData['email'] = $username;
                }
            } else {
                if (isset($body['email'])) {
                    $email = strtolower(trim((string) $body['email']));
                    if (empty($email)) {
                        return $this->errorResponse('Email address cannot be empty.', ['email' => 'Email address is required.'], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
                    }
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        return $this->errorResponse('Please enter a valid institutional email address.', ['email' => 'Invalid email address.'], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
                    }

                    $checkQuery = $this->userModel->groupStart()->where('email', $email);
                    if (!empty($caps['has_username'])) {
                        $checkQuery->orWhere('username', $email);
                    }
                    $existing = $checkQuery->groupEnd()->first();
                    if ($existing && (string) ($existing['id'] ?? '') !== (string) $userId) {
                        return $this->errorResponse('This email is already in use by another account.', ['email' => 'Email is already in use.'], ResponseInterface::HTTP_CONFLICT);
                    }
                    $updateData['email'] = $email;
                }
            }

            if (empty($updateData)) {
                return $this->errorResponse('No valid fields provided for update.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }

            $updateData['updated_at'] = date('Y-m-d H:i:s');
            $this->userModel->update($userId, $updateData);

            // Synchronize personnel table record if this user is linked to personnel
            if ($isPersonnel) {
                try {
                    $db = \Config\Database::connect();
                    if ($db->tableExists('personnel')) {
                        $pUpdate = [];
                        if (isset($updateData['first_name']) || isset($updateData['last_name'])) {
                            $newFirst = $updateData['first_name'] ?? ($currentUser['first_name'] ?? '');
                            $newLast  = $updateData['last_name'] ?? ($currentUser['last_name'] ?? '');
                            $pUpdate['name'] = trim("{$newFirst} {$newLast}");
                        }
                        if (isset($updateData['contact_number'])) {
                            if ($db->fieldExists('contact_number', 'personnel')) {
                                $pUpdate['contact_number'] = $updateData['contact_number'];
                            }
                        }
                        if (!empty($pUpdate)) {
                            $pBuilder = $db->table('personnel');
                            if ($db->fieldExists('user_id', 'personnel')) {
                                $pBuilder->where('user_id', $userId);
                            } else {
                                $oldFirst = $currentUser['first_name'] ?? '';
                                $oldLast  = $currentUser['last_name'] ?? '';
                                $oldFull  = trim("{$oldFirst} {$oldLast}");
                                $pBuilder->where('name', $oldFull);
                            }
                            $pBuilder->update($pUpdate);
                        }
                    }
                } catch (\Throwable $pErr) {
                    log_message('error', '[UserProfileController::updateProfile] Personnel sync error: ' . $pErr->getMessage());
                }
            }

            $safeUser = $this->userModel->getSafeUser($userId);

            $this->activityLogModel->logEvent([
                'event_type'     => 'ACCOUNT_PROFILE_UPDATED',
                'severity'       => 'info',
                'actor_id'       => $userId,
                'target_user_id' => $userId,
                'details'        => "User updated own profile information.",
                'metadata'       => ['updated_fields' => array_keys($updateData)],
            ]);

            return $this->successResponse('Profile updated successfully.', ['user' => $safeUser]);
        } catch (\Throwable $e) {
            log_message('error', '[UserProfileController::updateProfile] Exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return $this->errorResponse('Failed to update profile: ' . $e->getMessage(), [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Change the authenticated user's own password.
     * Requires: JwtAuthFilter
     */
    public function changePassword(): ResponseInterface
    {
        $userId = $this->currentUserId();
        $body   = $this->request->getJSON(true) ?? [];

        $currentPassword = $body['current_password'] ?? '';
        $newPassword     = $body['new_password'] ?? $body['password'] ?? '';
        $confirmPassword = $body['confirm_password'] ?? $body['new_password_confirm'] ?? '';

        if (empty($newPassword) || empty($confirmPassword)) {
            return $this->errorResponse('All password fields are required.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($newPassword !== $confirmPassword) {
            return $this->errorResponse('New passwords do not match.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (strlen($newPassword) < 8) {
            return $this->errorResponse('New password must be at least 8 characters long.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $this->userModel->find($userId);

        if (!empty($currentPassword) && !password_verify($currentPassword, $user['password_hash'])) {
            return $this->errorResponse('Current password is incorrect.', [], ResponseInterface::HTTP_UNAUTHORIZED);
        }

        $this->userModel->update($userId, [
            'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
        ]);

        $this->activityLogModel->logEvent([
            'event_type'     => 'ACCOUNT_PASSWORD_CHANGED',
            'severity'       => 'notice',
            'actor_id'       => $userId,
            'target_user_id' => $userId,
            'details'        => "User changed own account password.",
        ]);

        return $this->successResponse('Password changed successfully.');
    }

    /**
     * Upload avatar image for authenticated user.
     */
    public function uploadAvatar(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if (!$userId) {
            return $this->errorResponse('Unauthenticated.', [], ResponseInterface::HTTP_UNAUTHORIZED);
        }

        $file = $this->request->getFile('avatar');
        if (!$file || !$file->isValid() || $file->hasMoved()) {
            return $this->errorResponse('Please upload a valid image file using the "avatar" field.');
        }

        if ($file->getSizeByUnit('mb') > 3) {
            return $this->errorResponse('Avatar size must not exceed 3MB.');
        }

        $securityService = new FileSecurityService();
        $inspection = $securityService->inspectFile($file->getTempName(), $file->getClientName(), $file->getClientMimeType());
        if (!$inspection['safe']) {
            return $this->errorResponse('Security violation: ' . $inspection['reason'], [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $ext = strtolower($file->getClientExtension());
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return $this->errorResponse('Only JPG, PNG, and WebP images are allowed for avatars.');
        }

        $uploadDir = WRITEPATH . 'uploads/avatars/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }

        $fileName = 'avatar_' . $userId . '_' . time() . '.' . $ext;
        if (!$file->move($uploadDir, $fileName)) {
            return $this->errorResponse('Failed to save avatar image.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }

        $avatarRelativePath = 'avatars/' . $fileName;
        $this->userModel->update($userId, [
            'avatar_path' => $avatarRelativePath,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        $safeUser = $this->userModel->getSafeUser($userId);

        return $this->successResponse('Avatar uploaded successfully.', ['user' => $safeUser]);
    }

    /**
     * Publicly or authenticated stream avatar image.
     */
    public function getAvatar(string $userId)
    {
        $user = $this->userModel->find($userId);
        if (!$user || empty($user['avatar_path'])) {
            return $this->response->setStatusCode(404)->setBody('Avatar not found.');
        }

        $fullPath = WRITEPATH . 'uploads/' . $user['avatar_path'];
        if (!is_file($fullPath)) {
            return $this->response->setStatusCode(404)->setBody('Avatar file not found on disk.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $fullPath);
        finfo_close($finfo);

        return $this->response
            ->setHeader('Content-Type', $mime ?: 'image/jpeg')
            ->setHeader('Cache-Control', 'public, max-age=86400')
            ->setBody(file_get_contents($fullPath));
    }

    /**
     * Upload or update official signature for authenticated administrator or director.
     */
    public function uploadSignature(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if (!$userId) {
            return $this->errorResponse('Unauthenticated.', [], ResponseInterface::HTTP_UNAUTHORIZED);
        }

        $user = $this->userModel->find($userId);
        if (!$user) {
            return $this->errorResponse('User record not found.', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        $uploadDir = WRITEPATH . 'uploads/signatures/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }

        $fileName = 'signature_' . $userId . '_' . time() . '.png';
        $fullTarget = $uploadDir . $fileName;

        $file = $this->request->getFile('signature');
        if ($file && $file->isValid() && !$file->hasMoved()) {
            if ($file->getSizeByUnit('mb') > 3) {
                return $this->errorResponse('Signature image size must not exceed 3MB.');
            }
            $ext = strtolower($file->getClientExtension());
            if ($ext !== 'png') {
                return $this->errorResponse('Only transparent PNG (.png) images are allowed for signatures.');
            }

            if (!$file->move($uploadDir, $fileName)) {
                return $this->errorResponse('Failed to save signature file.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
            }
        } else {
            $json = $this->request->getJSON(true) ?: [];
            $base64Data = $json['signature_base64'] ?? $this->request->getPost('signature_base64') ?? '';

            if (empty($base64Data)) {
                return $this->errorResponse('Please provide a signature file or base64 image data.');
            }

            if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $type)) {
                $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
            }
            $decodedData = base64_decode($base64Data);
            if ($decodedData === false) {
                return $this->errorResponse('Invalid base64 signature image payload.');
            }
            if (strlen($decodedData) > 3 * 1024 * 1024) {
                return $this->errorResponse('Signature payload must not exceed 3MB.');
            }

            if (file_put_contents($fullTarget, $decodedData) === false) {
                return $this->errorResponse('Failed to write signature image to disk.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        if (!empty($user['signature_path'])) {
            $oldPath = WRITEPATH . 'uploads/' . $user['signature_path'];
            if (is_file($oldPath) && $oldPath !== $fullTarget) {
                @unlink($oldPath);
            }
        }

        $signatureRelativePath = 'signatures/' . $fileName;
        $this->userModel->update($userId, [
            'signature_path' => $signatureRelativePath,
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        $safeUser = $this->userModel->getSafeUser($userId);

        return $this->successResponse('Official signature saved successfully.', [
            'user'          => $safeUser,
            'signature_url' => base_url('api/v1/auth/signature/' . $userId),
        ]);
    }

    /**
     * Remove official signature for authenticated user.
     */
    public function deleteSignature(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if (!$userId) {
            return $this->errorResponse('Unauthenticated.', [], ResponseInterface::HTTP_UNAUTHORIZED);
        }

        $user = $this->userModel->find($userId);
        if ($user && !empty($user['signature_path'])) {
            $fullPath = WRITEPATH . 'uploads/' . $user['signature_path'];
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }

        $this->userModel->update($userId, [
            'signature_path' => null,
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        $safeUser = $this->userModel->getSafeUser($userId);

        return $this->successResponse('Official signature removed successfully.', ['user' => $safeUser]);
    }

    /**
     * Stream official signature image.
     */
    public function getSignature(string $userId)
    {
        $user = $this->userModel->find($userId);
        if (!$user || empty($user['signature_path'])) {
            return $this->response->setStatusCode(404)->setBody('Signature not found.');
        }

        $fullPath = WRITEPATH . 'uploads/' . $user['signature_path'];
        if (!is_file($fullPath)) {
            return $this->response->setStatusCode(404)->setBody('Signature file not found on disk.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $fullPath);
        finfo_close($finfo);

        return $this->response
            ->setHeader('Content-Type', $mime ?: 'image/png')
            ->setHeader('Cache-Control', 'no-cache, private, must-revalidate')
            ->setBody(file_get_contents($fullPath));
    }

    /**
     * Retrieve active official document signatures (Director & Unit Dispatcher)
     * for generating signed Job Orders and archived records.
     */
    public function getDocumentSignatures(): ResponseInterface
    {
        $unitParam = $this->request->getGet('unit');
        $unitId = null;

        if (!empty($unitParam)) {
            $upper = strtoupper(trim((string)$unitParam));
            if ($upper === 'FGMU' || $unitParam == '1') {
                $unitId = 1;
            } elseif ($upper === 'LEAU' || $unitParam == '2') {
                $unitId = 2;
            } elseif ($upper === 'SSU' || $unitParam == '3') {
                $unitId = 3;
            }
        }

        $director = $this->userModel->where('role', 'director')
            ->where('status', 'active')
            ->orderBy('id', 'ASC')
            ->first();

        $dispatcher = null;
        if ($unitId !== null) {
            $dispatcher = $this->userModel->where('role', 'admin')
                ->where('unit_id', $unitId)
                ->where('status', 'active')
                ->orderBy('id', 'ASC')
                ->first();
        }

        return $this->successResponse('Official document signatures retrieved.', [
            'director' => $director ? [
                'id'            => $director['id'],
                'name'          => trim(($director['first_name'] ?? '') . ' ' . ($director['last_name'] ?? '') . (!empty($director['name_extension']) ? (' ' . trim($director['name_extension'])) : '')),
                'role'          => 'director',
                'title'         => 'GSO Director / Authorized Rep.',
                'signature_url' => !empty($director['signature_path']) ? base_url('api/v1/auth/signature/' . $director['id']) : null,
            ] : null,
            'dispatcher' => $dispatcher ? [
                'id'            => $dispatcher['id'],
                'name'          => trim(($dispatcher['first_name'] ?? '') . ' ' . ($dispatcher['last_name'] ?? '') . (!empty($dispatcher['name_extension']) ? (' ' . trim($dispatcher['name_extension'])) : '')),
                'role'          => 'admin',
                'unit_id'       => $dispatcher['unit_id'] ?? $unitId,
                'title'         => ($unitParam ? strtoupper(trim((string)$unitParam)) : 'Unit') . ' Dispatcher / In-Charge',
                'signature_url' => !empty($dispatcher['signature_path']) ? base_url('api/v1/auth/signature/' . $dispatcher['id']) : null,
            ] : null,
        ]);
    }

    /**
     * Stream uploaded Employee / Student ID card image or Selfie with ID for verification.
     */
    public function getIdCard(string $userId)
    {
        $user = $this->userModel->find($userId);
        if (!$user) {
            return $this->response->setStatusCode(404)->setBody('User not found.');
        }

        $type = strtolower((string) ($this->request->getGet('type') ?? 'front'));
        $targetField = ($type === 'selfie') ? 'id_selfie_image' : 'id_card_image';

        if (empty($user[$targetField])) {
            return $this->response->setStatusCode(404)->setBody(($type === 'selfie' ? 'Selfie with ID' : 'Front ID card') . ' image not found.');
        }

        $relativePath = ltrim(str_replace('\\', '/', $user[$targetField]), '/');
        if (str_starts_with($relativePath, 'uploads/')) {
            $relativePath = substr($relativePath, 8);
        }

        $fullPath = WRITEPATH . 'uploads/' . $relativePath;
        if (!is_file($fullPath)) {
            $altPath = WRITEPATH . $relativePath;
            if (is_file($altPath)) {
                $fullPath = $altPath;
            } else {
                return $this->response->setStatusCode(404)->setBody('File not found on disk.');
            }
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $fullPath);
        finfo_close($finfo);

        return $this->response
            ->setHeader('Content-Type', $mime ?: 'image/jpeg')
            ->setHeader('Access-Control-Allow-Origin', '*')
            ->setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS')
            ->setHeader('Access-Control-Allow-Headers', '*')
            ->setHeader('Cache-Control', 'public, max-age=86400')
            ->setBody(file_get_contents($fullPath));
    }

    /**
     * Dedicated stream endpoint for Selfie with ID.
     */
    public function getIdSelfie(string $userId)
    {
        $_GET['type'] = 'selfie';
        return $this->getIdCard($userId);
    }
}
