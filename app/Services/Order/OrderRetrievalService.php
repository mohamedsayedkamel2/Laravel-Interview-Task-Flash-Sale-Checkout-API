<?php

namespace App\Services\Order;

use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Services\Holds\HoldRepository;
use Exception;

class OrderRetrievalService
{
    private HoldRepository $holdRepository;

    public function __construct(HoldRepository $holdRepository)
    {
        $this->holdRepository = $holdRepository;
    }

    public function getOrderWithHoldData(string $orderId): array
    {
        $order = Order::findOrFail($orderId);
        
        $hold = $this->holdRepository->getHold($order->hold_id);
        
        if (!$hold) {
            Log::warning("Hold not found for order", [
                'order_id' => $orderId,
                'hold_id' => $order->hold_id
            ]);
            
            return [
                'order_id' => $order->id,
                'hold_id' => $order->hold_id,
                'state' => $order->state,
                'product_id' => null,
                'quantity' => null,
                'hold_status' => 'not_found',
                'created_at' => $order->created_at->toISOString(),
                'updated_at' => $order->updated_at->toISOString(),
            ];
        }

        $productId = isset($hold['product_id']) ? (int) $hold['product_id'] : null;
        $quantity = isset($hold['qty']) ? (int) $hold['qty'] : null;

        return [
            'order_id' => $order->id,
            'hold_id' => $order->hold_id,
            'product_id' => $productId,
            'quantity' => $quantity,
            'state' => $order->state,
            'hold_status' => $hold['status'] ?? 'unknown',
            'created_at' => $order->created_at->toISOString(),
            'updated_at' => $order->updated_at->toISOString(),
        ];
    }

    public function getAllOrders(): array
    {
        $orders = Order::orderBy('created_at', 'desc')->get();
        
        $ordersWithHoldData = $orders->map(function ($order) {
            $hold = $this->holdRepository->getHold($order->hold_id);
            
            $productId = null;
            $quantity = null;
            $holdStatus = 'not_found';
            
            if ($hold) {
                $productId = isset($hold['product_id']) ? (int) $hold['product_id'] : null;
                $quantity = isset($hold['qty']) ? (int) $hold['qty'] : null;
                $holdStatus = $hold['status'] ?? 'unknown';
            }

            return [
                'order_id' => $order->id,
                'hold_id' => $order->hold_id,
                'product_id' => $productId,
                'quantity' => $quantity,
                'state' => $order->state,
                'hold_status' => $holdStatus,
                'created_at' => $order->created_at->toISOString(),
                'updated_at' => $order->updated_at->toISOString(),
            ];
        });

        return [
            'orders' => $ordersWithHoldData,
            'count' => $orders->count()
        ];
    }
}