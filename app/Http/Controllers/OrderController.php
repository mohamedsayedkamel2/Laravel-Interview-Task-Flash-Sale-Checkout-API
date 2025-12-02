<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\Order\OrderCreationService;
use App\Services\Order\OrderRetrievalService;
use App\Services\Holds\HoldValidationService;
use App\Http\Requests\CreateOrderRequest;
use App\Exceptions\HoldNotFoundException;
use App\Exceptions\HoldExpiredException;
use App\Exceptions\HoldAlreadyUsedException;
use App\Exceptions\RedisUnavailableException;
use Exception;

class OrderController extends Controller
{
    private OrderCreationService $orderCreationService;
    private OrderRetrievalService $orderRetrievalService;
    private HoldValidationService $holdValidationService;

    public function __construct(
        OrderCreationService $orderCreationService,
        OrderRetrievalService $orderRetrievalService,
        HoldValidationService $holdValidationService
    ) {
        $this->orderCreationService = $orderCreationService;
        $this->orderRetrievalService = $orderRetrievalService;
        $this->holdValidationService = $holdValidationService;
    }

    public function store(CreateOrderRequest $request): JsonResponse
    {
        try {
            $holdId = $request->validated()['hold_id'];
            
            $result = $this->orderCreationService->createOrderFromHold($holdId);

            return response()->json([
                'order_id' => $result['order']->id,
                'state' => $result['order']->state,
                'hold_id' => $holdId,
                'product_id' => $result['product_id'],
                'quantity' => $result['quantity']
            ], 201);

        } catch (HoldNotFoundException $e) {
            return $this->errorResponse(404, 'Hold not found');
            
        } catch (HoldExpiredException $e) {
            return $this->errorResponse(400, 'Hold expired', [
                'expires_at' => $e->getExpiresAt()
            ]);
            
        } catch (HoldAlreadyUsedException $e) {
            return $this->errorResponse(400, 'Hold already used');
            
        } catch (RedisUnavailableException $e) {
            return $this->errorResponse(503, 'Redis unavailable');
            
        } catch (Exception $e) {
            return $this->errorResponse(500, 'Failed to create order: ' . $e->getMessage());
        }
    }

    public function show($orderId): JsonResponse
    {
        try {
            $order = $this->orderRetrievalService->getOrderWithHoldData($orderId);
            return response()->json($order);

        } catch (Exception $e) {
            return $this->errorResponse(404, 'Order not found');
        }
    }

    public function checkHold($holdId): JsonResponse
    {
        try {
            $result = $this->holdValidationService->validateHold($holdId);
            return response()->json($result);

        } catch (Exception $e) {
            return $this->errorResponse(500, 'Failed to check hold');
        }
    }

    public function expireHold($holdId): JsonResponse
    {
        try {
            $result = $this->holdValidationService->expireHold($holdId);
            
            return response()->json([
                'message' => 'Hold manually expired',
                'hold_id' => $holdId,
                'product_id' => $result['product_id'],
                'released_quantity' => $result['released_quantity'],
            ]);

        } catch (HoldNotFoundException $e) {
            return $this->errorResponse(404, 'Hold not found');
            
        } catch (HoldAlreadyUsedException $e) {
            return $this->errorResponse(400, 'Hold already used, cannot expire');
            
        } catch (Exception $e) {
            return $this->errorResponse(500, 'Failed to expire hold: ' . $e->getMessage());
        }
    }

    public function index(): JsonResponse
    {
        try {
            $orders = $this->orderRetrievalService->getAllOrders();
            return response()->json($orders);

        } catch (Exception $e) {
            return $this->errorResponse(500, 'Failed to retrieve orders');
        }
    }

    public function debugHold($holdId): JsonResponse
    {
        try {
            $debugData = $this->holdValidationService->debugHold($holdId);
            return response()->json($debugData);

        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function errorResponse(int $status, string $message, array $data = []): JsonResponse
    {
        $response = ['error' => $message];
        if (!empty($data)) {
            $response = array_merge($response, $data);
        }
        return response()->json($response, $status);
    }
}