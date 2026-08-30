<?php

namespace App\Controllers\API;

use App\Controllers\BaseController;
use App\Models\VehicleModel;
use App\Models\TicketLogModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * TasuController
 *
 * Manages the TASU vehicle fleet and dispatch board.
 *
 * Endpoints:
 *  GET    /api/v1/tasu/vehicles              - All TASU fleet vehicles
 *  GET    /api/v1/tasu/vehicles/available    - Available vehicles only (for requestors)
 *  POST   /api/v1/tasu/vehicles              - Add a vehicle to the fleet
 *  PUT    /api/v1/tasu/vehicles/:id          - Update vehicle details
 *  DELETE /api/v1/tasu/vehicles/:id          - Remove a vehicle
 *  PATCH  /api/v1/tasu/vehicles/:id/status   - Update vehicle status
 *  GET    /api/v1/tasu/dispatch              - Dispatch board (vehicles + active bookings)
 */
class TasuController extends BaseController
{
    private VehicleModel $vehicleModel;

    private const TASU_UNIT_ID = 4;

    public function __construct()
    {
        $this->vehicleModel = new VehicleModel();
    }

    /**
     * Get all TASU fleet vehicles (for admin vehicle management view).
     */
    public function fleet(): ResponseInterface
    {
        $vehicles = $this->vehicleModel->getTasuFleet(self::TASU_UNIT_ID);

        return $this->successResponse('TASU fleet retrieved.', [
            'vehicles' => $vehicles,
            'count'    => count($vehicles),
        ]);
    }

    /**
     * Get only available vehicles (for requestors on VehicleAvailabilityView).
     */
    public function available(): ResponseInterface
    {
        $vehicles = $this->vehicleModel->getAvailable();

        return $this->successResponse('Available vehicles retrieved.', [
            'vehicles' => $vehicles,
            'count'    => count($vehicles),
        ]);
    }

    /**
     * Add a new vehicle to the TASU fleet.
     *
     * Body: { plate_no, model_name, model_year, fuel_type, engine_specs, category, image_url? }
     */
    public function create(): ResponseInterface
    {
        $body = $this->request->getPost();
        if (empty($body)) {
            try {
                $body = $this->request->getJSON(true) ?? [];
            } catch (\Throwable $e) {
                $body = [];
            }
        }

        $required = ['plate_no', 'model_name', 'category'];
        foreach ($required as $field) {
            if (empty($body[$field])) {
                return $this->errorResponse("{$field} is required.", [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $category = sanitize_string($body['category'] ?? '');
        $validCategories = ['Van', 'Pickup', 'Bus', 'SUV', 'Logistics', 'Sedan', 'Other'];
        
        if (!in_array($category, $validCategories, true)) {
            return $this->errorResponse('Invalid vehicle category.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Check for duplicate plate_no
        $existing = $this->vehicleModel->where('plate_no', sanitize_string($body['plate_no'] ?? ''))->first();
        if ($existing) {
            return $this->errorResponse('A vehicle with this plate number already exists.');
        }

        $imageUrl = sanitize_string($body['image_url'] ?? '');

        // Handle File Upload
        $file = $this->request->getFile('image');
        if ($file && $file->isValid() && !$file->hasMoved()) {
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'];
            $mime = $file->getMimeType();
            $clientMime = $file->getClientMimeType();

            if (!in_array($mime, $allowedMimes, true) && !in_array($clientMime, $allowedMimes, true)) {
                return $this->errorResponse('Invalid image type. Please upload a JPG, PNG, WEBP, or GIF image.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }

            try {
                $uploadDir = FCPATH . 'uploads/vehicles/';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0775, true);
                }
                $newName = $file->getRandomName();
                if ($file->move($uploadDir, $newName)) {
                    $imageUrl = base_url('uploads/vehicles/' . $newName);
                }
            } catch (\Exception $e) {
                log_message('error', 'Vehicle image upload failed: ' . $e->getMessage());
                return $this->errorResponse('Failed to upload image. Please check server folder permissions.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
            }
        } elseif ($file && !$file->isValid() && $file->getError() !== UPLOAD_ERR_NO_FILE) {
            return $this->errorResponse('Image upload error: ' . $file->getErrorString(), [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $vehicleId = $this->vehicleModel->insert([
            'unit_id'          => self::TASU_UNIT_ID,
            'plate_no'         => sanitize_string($body['plate_no'] ?? ''),
            'model_name'       => sanitize_string($body['model_name'] ?? ''),
            'model_year'       => sanitize_string($body['model_year'] ?? ''),
            'fuel_type'        => sanitize_string($body['fuel_type'] ?? ''),
            'engine_specs'     => sanitize_string($body['engine_specs'] ?? ''),
            'category'         => $category,
            'status'           => 'available',
            'image_url'        => $imageUrl,
            'registered_owner' => sanitize_string($body['registered_owner'] ?? 'Benguet State University'),
        ], true);

        if (!$vehicleId) {
            $errors = $this->vehicleModel->errors();
            $errorMessage = !empty($errors) ? implode(', ', $errors) : 'Failed to register vehicle.';
            return $this->errorResponse($errorMessage, $errors, ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->successResponse('Vehicle added to fleet.', ['vehicle_id' => $vehicleId], ResponseInterface::HTTP_CREATED);
    }

    /**
     * Update an existing vehicle's details.
     */
    public function update(int $vehicleId): ResponseInterface
    {
        $vehicle = $this->vehicleModel->find($vehicleId);
        if (!$vehicle) {
            return $this->notFoundResponse('Vehicle');
        }

        $body = $this->request->getPost();
        if (empty($body)) {
            try {
                $body = $this->request->getJSON(true) ?? [];
            } catch (\Throwable $e) {
                $body = [];
            }
        }

        $updateData = [];

        $stringFields = ['plate_no', 'model_name', 'model_year', 'fuel_type', 'engine_specs', 'image_url', 'registered_owner'];
        foreach ($stringFields as $field) {
            if (isset($body[$field])) {
                $updateData[$field] = sanitize_string($body[$field]);
            }
        }

        if (isset($body['plate_no'])) {
            $existing = $this->vehicleModel->where('plate_no', sanitize_string($body['plate_no']))
                                           ->where('id !=', $vehicleId)
                                           ->first();
            if ($existing) {
                return $this->errorResponse('A vehicle with this plate number already exists.');
            }
        }

        if (isset($body['category'])) {
            $validCategories = ['Van', 'Pickup', 'Bus', 'SUV', 'Logistics', 'Sedan', 'Other'];
            if (!in_array($body['category'], $validCategories, true)) {
                return $this->errorResponse('Invalid vehicle category.');
            }
            $updateData['category'] = $body['category'];
        }

        // Handle File Upload
        $file = $this->request->getFile('image');
        if ($file && $file->isValid() && !$file->hasMoved()) {
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'];
            $mime = $file->getMimeType();
            $clientMime = $file->getClientMimeType();

            if (!in_array($mime, $allowedMimes, true) && !in_array($clientMime, $allowedMimes, true)) {
                return $this->errorResponse('Invalid image type. Please upload a JPG, PNG, WEBP, or GIF image.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }

            try {
                $uploadDir = FCPATH . 'uploads/vehicles/';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0775, true);
                }
                $newName = $file->getRandomName();
                if ($file->move($uploadDir, $newName)) {
                    $updateData['image_url'] = base_url('uploads/vehicles/' . $newName);
                }
            } catch (\Exception $e) {
                log_message('error', 'Vehicle image upload failed: ' . $e->getMessage());
                return $this->errorResponse('Failed to upload image. Please check server folder permissions.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
            }
        } elseif ($file && !$file->isValid() && $file->getError() !== UPLOAD_ERR_NO_FILE) {
            return $this->errorResponse('Image upload error: ' . $file->getErrorString(), [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!empty($updateData)) {
            $updateData['updated_at'] = date('Y-m-d H:i:s');
            $updated = $this->vehicleModel->update($vehicleId, $updateData);
            if ($updated === false) {
                $errors = $this->vehicleModel->errors();
                $errorMessage = !empty($errors) ? implode(', ', $errors) : 'Failed to update vehicle.';
                return $this->errorResponse($errorMessage, $errors, ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        return $this->successResponse('Vehicle updated.', ['vehicle_id' => $vehicleId]);
    }

    /**
     * Update a vehicle's status (e.g., mark as 'maintenance').
     *
     * Body: { status: 'available' | 'maintenance' | 'reserved' }
     */
    public function updateStatus(int $vehicleId): ResponseInterface
    {
        $vehicle = $this->vehicleModel->find($vehicleId);
        if (!$vehicle) {
            return $this->notFoundResponse('Vehicle');
        }

        $body   = $this->request->getJSON(true) ?? [];
        $status = sanitize_string($body['status'] ?? '');

        // 'in_use' is set automatically by dispatch flow, not manually
        $allowedStatuses = ['available', 'maintenance', 'reserved'];
        if (!in_array($status, $allowedStatuses, true)) {
            return $this->errorResponse("Status must be one of: " . implode(', ', $allowedStatuses));
        }

        $this->vehicleModel->update($vehicleId, [
            'status'     => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->successResponse("Vehicle status updated to '{$status}'.", ['vehicle_id' => $vehicleId, 'status' => $status]);
    }

    /**
     * Remove a vehicle from the fleet.
     * Only allowed if the vehicle is not currently in use.
     */
    public function delete(int $vehicleId): ResponseInterface
    {
        $vehicle = $this->vehicleModel->find($vehicleId);
        if (!$vehicle) {
            return $this->notFoundResponse('Vehicle');
        }

        if ($vehicle['status'] === 'in_use') {
            return $this->errorResponse('Cannot delete a vehicle that is currently in use.');
        }

        $this->vehicleModel->delete($vehicleId);

        return $this->successResponse('Vehicle removed from fleet.', ['vehicle_id' => $vehicleId]);
    }

    /**
     * Get full dispatch board data: all vehicles with active trip bookings.
     * Used by the TASU Dispatch Board calendar/table view.
     */
    public function dispatchBoard(): ResponseInterface
    {
        $data = $this->vehicleModel->getDispatchBoardData(self::TASU_UNIT_ID);

        return $this->successResponse('Dispatch board data retrieved.', ['dispatch' => $data]);
    }
}
