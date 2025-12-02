<?php

namespace App\Services\Payment;

use App\Models\Order;
use App\Services\Holds\HoldManagementService;
use App\Services\Holds\HoldRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Exceptions\InvalidHoldException;

class PaymentProcessor
{
    private HoldManagementService $holdManagementService;
    private HoldRepository $holdRepository;
    
    public function __construct(
        HoldManagementService $holdManagementService,
        HoldRepository $holdRepository
    ) {
        $this->holdManagementService = $holdManagementService;
        $this->holdRepository = $holdRepository;
    }
    
    public function processSuccess(Order $order, $idempotencyRecord): array
    {
        try {
            $hold = $this->holdRepository->getHold($order->hold_id);
            
            if (!$hold) {
                return $this->handleExpiredHold($order, 'payment success');
            }
            
            $productId = $hold['product_id'];
            $quantity = $hold['qty'];
            
            $currentStatus = $hold['status'] ?? null;
            
            if ($currentStatus === 'used') {
                return $this->handleAlreadyUsedHold($order);
            }
            
            if ($currentStatus === 'payment_failed') {
                throw new \Exception("Payment state conflict: Cannot mark as paid - payment already failed", 409);
            }
            
            if ($currentStatus !== 'active') {
                throw new \Exception("Hold is not in active state");
            }
            
            $this->updateProductStock($productId, $quantity);
            
            $order->state = 'paid';
            $order->save();
            
            $this->processPaymentSuccess($order->hold_id, $productId, $quantity, $order->id);
            
            Log::info("Payment succeeded", [
                'order_id' => $order->id,
                'product_id' => $productId,
                'quantity' => $quantity,
                'mysql_stock_updated' => true,
                'redis_hold_updated' => true
            ]);
            
            return [
                'status' => 200,
                'data' => [
                    'message' => 'Payment succeeded — order marked as paid',
                    'order_id' => $order->id,
                    'state' => 'paid',
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'hold_status' => 'deleted',
                    'timestamp' => now()->toISOString()
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error("Payment success processing failed", [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }
    
    public function processFailure(Order $order, $idempotencyRecord): array
    {
        try {
            $hold = $this->holdRepository->getHold($order->hold_id);
            
            if (!$hold) {
                return $this->handleExpiredHold($order, 'payment failure');
            }
            
            $productId = $hold['product_id'];
            $quantity = $hold['qty'];
            
            $currentStatus = $hold['status'] ?? null;
            
            if ($currentStatus === 'payment_failed') {
                return $this->handleAlreadyFailedHold($order);
            }
            
            if ($currentStatus === 'used') {
                throw new \Exception("Payment state conflict: Cannot mark as failed - order already paid", 409);
            }
            
            if ($currentStatus !== 'active') {
                throw new \Exception("Hold is not in active state");
            }
            
            $order->state = 'cancelled';
            $order->save();
            
            $this->processPaymentFailure($order->hold_id, $productId, $quantity, $order->id);
            
            Log::info("Payment failed - stock restored", [
                'order_id' => $order->id,
                'product_id' => $productId,
                'quantity_restored' => $quantity,
                'order_cancelled' => true,
                'stock_restored' => true
            ]);
            
            return [
                'status' => 200,
                'data' => [
                    'message' => 'Payment failed — order cancelled and stock restored',
                    'order_id' => $order->id,
                    'state' => 'cancelled',
                    'product_id' => $productId,
                    'quantity_restored' => $quantity,
                    'hold_status' => 'deleted',
                    'timestamp' => now()->toISOString()
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error("Payment failure processing failed", [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }
    
    public function processSuccessPipeline(Order $order, $idempotencyRecord): array
    {
        $hold = $this->holdRepository->getHold($order->hold_id);
        
        if (!$hold) {
            return $this->handleExpiredHold($order, 'payment success pipeline');
        }
        
        $productId = $hold['product_id'];
        $quantity = $hold['qty'];
        
        $currentStatus = $hold['status'] ?? null;
        
        if ($currentStatus === 'used') {
            if ($order->state !== 'paid') {
                $order->state = 'paid';
                $order->save();
            }
            return [
                'status' => 200,
                'data' => [
                    'message' => 'Payment already processed',
                    'order_id' => $order->id,
                    'state' => 'paid',
                    'timestamp' => now()->toISOString()
                ]
            ];
        }
        
        if ($currentStatus === 'payment_failed') {
            return [
                'status' => 409,
                'data' => [
                    'error' => 'Payment state conflict',
                    'message' => 'Cannot mark as paid - payment already failed'
                ]
            ];
        }
        
        $this->updateProductStock($productId, $quantity);
        
        $order->state = 'paid';
        $order->save();
        
        $this->processPaymentSuccessPipeline($order->hold_id, $productId, $quantity, $order->id);
        
        Log::info("Payment succeeded via pipeline", [
            'order_id' => $order->id,
            'product_id' => $productId,
            'quantity' => $quantity,
            'method' => 'pipeline'
        ]);
        
        return [
            'status' => 200,
            'data' => [
                'message' => 'Payment succeeded',
                'order_id' => $order->id,
                'state' => 'paid',
                'product_id' => $productId,
                'quantity' => $quantity,
                'timestamp' => now()->toISOString()
            ]
        ];
    }
    
    private function handleExpiredHold(Order $order, string $context): array
    {
        Log::warning("Hold key expired - order invalid", [
            'order_id' => $order->id,
            'hold_id' => $order->hold_id,
            'context' => $context,
            'current_order_state' => $order->state
        ]);
        
        $order->state = 'cancelled';
        $order->save();
        
        return [
            'status' => 410,
            'data' => [
                'error' => 'hold_expired',
                'message' => 'Hold key has expired - order is now cancelled',
                'order_id' => $order->id,
                'state' => 'cancelled',
                'hold_id' => $order->hold_id,
                'context' => $context,
                'timestamp' => now()->toISOString(),
                'resolution' => 'Please create a new order with a fresh hold'
            ]
        ];
    }
    
    private function processPaymentSuccess(string $holdId, int $productId, int $quantity, int $orderId): void
    {
        $redis = Redis::connection();
        $holdKey = "hold:{$holdId}";
        $productHoldsSet = "product_holds:{$productId}";
        
        $redis->watch([$holdKey, $productHoldsSet]);
        
        try {
            $hold = $redis->hgetall($holdKey);
            
            if (!$hold || !isset($hold['product_id'], $hold['qty'])) {
                throw new InvalidHoldException("Invalid hold data");
            }
            
            if (($hold['status'] ?? '') === 'used') {
                $redis->unwatch();
                return;
            }
            
            if (($hold['status'] ?? '') === 'payment_failed') {
                $redis->unwatch();
                throw new \Exception("Cannot mark as paid - payment already failed", 409);
            }
            
            $redis->multi();
            
            $redis->decrby("reserved_stock:{$productId}", $quantity);
            $redis->incr("stock_version:{$productId}");
            $redis->decrby("active_holds:{$productId}", $quantity);
            
            $redis->del($holdKey);
            
            $redis->srem($productHoldsSet, $holdId);
            
            $result = $redis->exec();
            
            if ($result === null) {
                throw new \Exception("Redis transaction failed - concurrent modification detected");
            }
            
            Log::debug("Payment success processed in Redis - hold deleted", [
                'hold_id' => $holdId,
                'order_id' => $orderId,
                'product_id' => $productId,
                'quantity' => $quantity
            ]);
            
        } catch (\Exception $e) {
            $redis->unwatch();
            throw $e;
        }
    }
    
    private function processPaymentFailure(string $holdId, int $productId, int $quantity, int $orderId): void
    {
        $redis = Redis::connection();
        $holdKey = "hold:{$holdId}";
        $productHoldsSet = "product_holds:{$productId}";
        
        $redis->watch([$holdKey, $productHoldsSet]);
        
        try {
            $hold = $redis->hgetall($holdKey);
            
            if (!$hold || !isset($hold['product_id'], $hold['qty'])) {
                throw new InvalidHoldException("Invalid hold data");
            }
            
            if (($hold['status'] ?? '') === 'payment_failed') {
                $redis->unwatch();
                return;
            }
            
            if (($hold['status'] ?? '') === 'used') {
                $redis->unwatch();
                throw new \Exception("Cannot mark as failed - order already paid", 409);
            }
            
            $redis->multi();
            
            $redis->incrby("available_stock:{$productId}", $quantity);
            $redis->decrby("reserved_stock:{$productId}", $quantity);
            $redis->incr("stock_version:{$productId}");
            $redis->decrby("active_holds:{$productId}", $quantity);
            
            $redis->del($holdKey);
            
            $redis->srem($productHoldsSet, $holdId);
            
            $result = $redis->exec();
            
            if ($result === null) {
                throw new \Exception("Redis transaction failed - concurrent modification detected");
            }
            
            Log::debug("Payment failure processed in Redis - hold deleted", [
                'hold_id' => $holdId,
                'order_id' => $orderId,
                'product_id' => $productId,
                'quantity_restored' => $quantity
            ]);
            
        } catch (\Exception $e) {
            $redis->unwatch();
            throw $e;
        }
    }
    
    private function processPaymentSuccessPipeline(string $holdId, int $productId, int $quantity, int $orderId): void
    {
        Redis::pipeline(function ($pipe) use ($holdId, $productId, $quantity, $orderId) {
            $pipe->decrby("reserved_stock:{$productId}", $quantity);
            $pipe->incr("stock_version:{$productId}");
            $pipe->decrby("active_holds:{$productId}", $quantity);
            
            $pipe->del("hold:{$holdId}");
            
            $pipe->srem("product_holds:{$productId}", $holdId);
        });
    }
    
    private function updateProductStock(int $productId, int $quantity): void
    {
        $updated = DB::table('products')
            ->where('id', $productId)
            ->where('stock', '>=', $quantity)
            ->decrement('stock', $quantity);
        
        if ($updated === 0) {
            $product = DB::table('products')->where('id', $productId)->first();
            
            if (!$product) {
                throw new \Exception("Product not found");
            }
            
            if ($product->stock < $quantity) {
                Log::error("Insufficient stock for payment", [
                    'product_id' => $productId,
                    'required' => $quantity,
                    'available' => $product->stock,
                    'source' => 'mysql'
                ]);
                
                throw new \Exception("Insufficient stock: required {$quantity}, available {$product->stock}");
            }
            
            throw new \Exception("Concurrent stock modification detected");
        }
    }
    
    private function handleAlreadyUsedHold(Order $order): array
    {
        if ($order->state !== 'paid') {
            $order->state = 'paid';
            $order->save();
        }
        
        return [
            'status' => 200,
            'data' => [
                'message' => 'Payment already processed',
                'order_id' => $order->id,
                'state' => 'paid',
                'timestamp' => now()->toISOString()
            ]
        ];
    }
    
    private function handleAlreadyFailedHold(Order $order): array
    {
        if ($order->state !== 'cancelled') {
            $order->state = 'cancelled';
            $order->save();
        }
        
        return [
            'status' => 200,
            'data' => [
                'message' => 'Payment already marked as failed',
                'order_id' => $order->id,
                'state' => 'cancelled',
                'timestamp' => now()->toISOString()
            ]
        ];
    }
}