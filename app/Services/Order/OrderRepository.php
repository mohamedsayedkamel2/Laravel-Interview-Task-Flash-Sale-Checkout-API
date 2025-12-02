<?php

namespace App\Services\Order;

use App\Models\Order;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

class OrderRepository
{
    /**
     * Find order with lock for update (for webhook processing)
     */
    public function findWithLock(int $orderId): ?Order
    {
        return Order::where('id', $orderId)->lockForUpdate()->first();
    }
    
    /**
     * Find order without lock
     */
    public function find(int $orderId): ?Order
    {
        return Order::find($orderId);
    }
    
    /**
     * Find order or throw exception
     */
    public function findOrFail(int $orderId): Order
    {
        $order = Order::find($orderId);
        
        if (!$order) {
            throw new ModelNotFoundException("Order not found: {$orderId}");
        }
        
        return $order;
    }
    
    /**
     * Find order by hold ID
     */
    public function findByHoldId(string $holdId): ?Order
    {
        return Order::where('hold_id', $holdId)->first();
    }
    
    public function findByHoldIdWithLock(string $holdId): ?Order
    {
        return Order::where('hold_id', $holdId)->lockForUpdate()->first();
    }
    
    public function updateState(int $orderId, string $state): bool
    {
        $order = $this->find($orderId);
        
        if (!$order) {
            return false;
        }
        
        $order->state = $state;
        return $order->save();
    }
    
    public function updateStateWithLock(int $orderId, string $oldState, string $newState): bool
    {
        return Order::where('id', $orderId)
            ->where('state', $oldState)
            ->update([
                'state' => $newState,
                'updated_at' => now()
            ]) > 0;
    }
    
    public function createFromHold(string $holdId, array $additionalData = []): Order
    {
        return Order::create(array_merge([
            'hold_id' => $holdId,
            'state' => 'pending_payment',
            'created_at' => now(),
            'updated_at' => now()
        ], $additionalData));
    }
    
    public function countByState(string $state): int
    {
        return Order::where('state', $state)->count();
    }
    
    public function getRecentOrders(int $limit = 50): array
    {
        return Order::orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }
    
    public function exists(int $orderId): bool
    {
        return Order::where('id', $orderId)->exists();
    }
    
    public function getPendingOrders(int $limit = 100): array
    {
        return Order::where('state', 'pending_payment')
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get()
            ->toArray();
    }
}