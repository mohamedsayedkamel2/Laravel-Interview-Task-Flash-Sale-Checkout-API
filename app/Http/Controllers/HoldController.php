<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\RedisUnavailableException;
use App\Exceptions\HoldNotFoundException;
use App\Exceptions\InvalidHoldException;
use App\Exceptions\HoldNotExpiredException;
use Illuminate\Validation\ValidationException;
use App\Services\Holds\HoldCreationService;
use App\Services\Holds\HoldManagementService;
use App\Services\Stock\StockService;
use App\Services\Holds\HoldRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Exception;

class HoldController extends Controller
{
    private HoldCreationService $holdCreationService;
    private HoldManagementService $holdManagementService;
    private StockService $stockService;
    private HoldRepository $holdRepository;

    public function __construct(
        HoldCreationService $holdCreationService,
        HoldManagementService $holdManagementService,
        StockService $stockService,
        HoldRepository $holdRepository
    ) {
        $this->holdCreationService = $holdCreationService;
        $this->holdManagementService = $holdManagementService;
        $this->stockService = $stockService;
        $this->holdRepository = $holdRepository;
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $this->validateCreateRequest($request);
            
            $result = $this->holdCreationService->createHold(
                $validated['product_id'], 
                $validated['qty']
            );

            return $this->successResponse($result, 201);

        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
            
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse(404, 'Product not found');
            
        } catch (InsufficientStockException $e) {
            return $this->errorResponse(400, 'Not enough stock', [
                'available_stock' => $e->getAvailableStock(),
                'reserved_stock' => $e->getReservedStock(),
                'version' => $e->getVersion()
            ]);
            
        } catch (RedisUnavailableException $e) {
            return $this->errorResponse(503, 'Redis unavailable');
            
        } catch (Exception $e) {
            Log::error('Hold creation failed', [
                'product_id' => $request->input('product_id'),
                'quantity' => $request->input('qty'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->errorResponse(500, 'Failed to create hold: ' . $e->getMessage());
        }
    }

    public function release(string $holdId): JsonResponse
    {
        try {
            $result = $this->holdManagementService->releaseHold($holdId);
            
            return $this->successResponse([
                'message' => 'Hold released',
                'hold_id' => $holdId,
                'product_id' => $result['product_id'],
                'released_qty' => $result['released_qty']
            ]);

        } catch (HoldNotFoundException $e) {
            return $this->errorResponse(404, 'Hold not found');
            
        } catch (InvalidHoldException $e) {
            return $this->errorResponse(400, $e->getMessage());
            
        } catch (Exception $e) {
            Log::error('Hold release failed', [
                'hold_id' => $holdId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->errorResponse(500, 'Failed to release hold: ' . $e->getMessage());
        }
    }

    public function show(string $holdId): JsonResponse
    {
        try {
            $hold = $this->holdRepository->getHold($holdId);
            
            if (!$hold) {
                return $this->errorResponse(404, 'Hold not found');
            }

            $result = [
                'hold_id'    => $holdId,
                'product_id' => $hold['product_id'] ?? null,
                'quantity'   => (int) ($hold['qty'] ?? 0),
                'created_at' => $hold['created_at'] ?? null,
                'expires_at' => $hold['expires_at'] ?? null,
                'status'     => $hold['status'] ?? 'unknown',
                'is_expired' => $this->isHoldExpired($hold),
                // Removed TTL field since we're not using Redis TTL
            ];

            return $this->successResponse($result);

        } catch (Exception $e) {
            Log::error('Failed to fetch hold', [
                'hold_id' => $holdId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->errorResponse(500, 'Failed to fetch hold: ' . $e->getMessage());
        }
    }

    public function expire(string $holdId): JsonResponse
    {
        try {
            $result = $this->holdManagementService->expireHold($holdId);
            
            return $this->successResponse([
                'message' => 'Hold expired',
                'hold_id' => $holdId,
                'product_id' => $result['product_id'],
                'expired_qty' => $result['expired_qty']
            ]);

        } catch (HoldNotFoundException $e) {
            return $this->errorResponse(404, 'Hold not found');
            
        } catch (HoldNotExpiredException $e) {
            return $this->errorResponse(400, 'Hold not yet expired', [
                'expires_at' => $e->getExpiresAt(),
                'seconds_remaining' => $e->getSecondsRemaining()
            ]);
            
        } catch (InvalidHoldException $e) {
            return $this->errorResponse(400, $e->getMessage());
            
        } catch (Exception $e) {
            Log::error('Hold expire failed', [
                'hold_id' => $holdId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->errorResponse(500, 'Failed to expire hold: ' . $e->getMessage());
        }
    }

    public function cleanupExpired(): JsonResponse
    {
        try {
            $result = $this->holdManagementService->cleanupExpiredHolds();
            
            return $this->successResponse([
                'message' => 'Cleanup completed',
                'expired_holds_count' => $result['expired_count'],
                'released_stock_total' => $result['released_stock']
            ]);

        } catch (Exception $e) {
            Log::error('Expired holds cleanup failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->errorResponse(500, 'Cleanup failed: ' . $e->getMessage());
        }
    }

    private function validateCreateRequest(Request $request): array
    {
        return $request->validate([
            'product_id' => 'required|integer|min:1|exists:products,id',
            'qty'        => 'required|integer|min:1|max:1000'
        ]);
    }

    private function isHoldExpired(array $hold): bool
    {
        if (!isset($hold['expires_at_timestamp'])) {
            return false;
        }
        
        return time() > (int) $hold['expires_at_timestamp'];
    }

    private function successResponse(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status);
    }

    private function errorResponse(int $status, string $message, array $data = []): JsonResponse
    {
        $response = ['error' => $message];
        if (!empty($data)) {
            $response = array_merge($response, $data);
        }
        return response()->json($response, $status);
    }

    private function validationErrorResponse(ValidationException $e): JsonResponse
    {
        $errors = $e->errors();
        if (empty($errors)) {
            return $this->errorResponse(422, 'Validation failed');
        }
        
        $firstErrorKey = array_key_first($errors);
        $firstErrorMessage = $errors[$firstErrorKey][0] ?? 'Validation failed';
        
        return $this->errorResponse(422, $firstErrorMessage);
    }
}