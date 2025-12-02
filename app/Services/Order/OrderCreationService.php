<?php

namespace App\Services\Order;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Exceptions\HoldNotFoundException;
use App\Exceptions\HoldExpiredException;
use App\Exceptions\HoldAlreadyUsedException;
use App\Exceptions\RedisUnavailableException;
use App\Exceptions\ConcurrentModificationException;
use Exception;

class OrderCreationService
{
    private const MAX_RETRY_ATTEMPTS = 3;
    private const RETRY_DELAY_MS = 100;

    public function __construct()
    {
    }

    public function createOrderFromHold(string $holdId): array
    {
        $this->checkRedisAvailability();
        Log::info("Order creation started", ['hold_id' => $holdId]);

        $holdData = $this->validateAndGetHoldData($holdId);

        $order = $this->createOrderInDatabase($holdId);

        Log::info("Order created successfully", [
            'order_id' => $order->id,
            'hold_id' => $holdId,
            'product_id' => $holdData['product_id'],
            'quantity' => $holdData['quantity']
        ]);

        return [
            'order' => $order,
            'product_id' => $holdData['product_id'],
            'quantity' => $holdData['quantity'],
            'hold_data' => $holdData
        ];
    }

    private function validateAndGetHoldData(string $holdId): array
    {
        for ($attempt = 1; $attempt <= self::MAX_RETRY_ATTEMPTS; $attempt++) {
            try {
                Log::info("Validating hold", [
                    'hold_id' => $holdId,
                    'attempt' => $attempt
                ]);
                
                $result = $this->executeHoldValidationTransaction($holdId);
                
                Log::info("Hold validation successful", [
                    'hold_id' => $holdId,
                    'product_id' => $result['product_id'],
                    'quantity' => $result['quantity']
                ]);
                
                return $result;
                
            } catch (ConcurrentModificationException $e) {
                if ($attempt < self::MAX_RETRY_ATTEMPTS) {
                    Log::warning("Concurrent modification, retrying", [
                        'hold_id' => $holdId,
                        'attempt' => $attempt
                    ]);
                    usleep(self::RETRY_DELAY_MS * 1000 * $attempt);
                    continue;
                }
                throw new Exception('Failed to validate hold after retries: ' . $e->getMessage());
            } catch (Exception $e) {
                Log::error("Hold validation attempt $attempt failed", [
                    'hold_id' => $holdId,
                    'error' => $e->getMessage()
                ]);
                
                if ($attempt === self::MAX_RETRY_ATTEMPTS) {
                    throw $e;
                }
            }
        }

        throw new Exception('Failed to validate hold after all retries');
    }

    private function executeHoldValidationTransaction(string $holdId): array
    {
        $redis = Redis::connection();
        $holdKey = "hold:{$holdId}";

        $redis->watch([$holdKey]);

        try {
            $hold = $redis->hgetall($holdKey);
            
            if (empty($hold)) {
                $redis->unwatch();
                Log::warning("Hold not found in Redis", ['hold_id' => $holdId]);
                throw new HoldNotFoundException();
            }

            Log::debug("Hold data retrieved from Redis", [
                'hold_id' => $holdId,
                'hold_data' => $hold
            ]);

            $productId = $hold['product_id'] ?? null;
            $quantityRaw = $hold['qty'] ?? '0';
            $status = $hold['status'] ?? 'active';
            $expiresAtTimestamp = $hold['expires_at_timestamp'] ?? null;
            $expiresAt = $hold['expires_at'] ?? null;

            $quantity = (int) $quantityRaw;
            $productId = $productId ? (int) $productId : null;

            if ($status === 'used') {
                $redis->unwatch();
                Log::warning("Hold already used", ['hold_id' => $holdId]);
                throw new HoldAlreadyUsedException();
            }

            if ($status === 'payment_failed') {
                $redis->unwatch();
                Log::warning("Hold previously had payment failure", ['hold_id' => $holdId]);
                throw new Exception('Hold has previous payment failure');
            }

            if ($status === 'expired') {
                $redis->unwatch();
                Log::warning("Hold already expired", ['hold_id' => $holdId]);
                throw new HoldExpiredException($expiresAt);
            }

            if ($expiresAtTimestamp && time() > (int) $expiresAtTimestamp) {
                Log::warning("Hold expired by timestamp", [
                    'hold_id' => $holdId,
                    'expires_at_timestamp' => $expiresAtTimestamp,
                    'current_time' => time()
                ]);
                
                $redis->multi();
                $redis->hmset($holdKey, [
                    'status' => 'expired',
                    'expired_at' => time(),
                    'expired_at_iso' => date('c')
                ]);
                
                if ($productId) {
                    $redis->srem("product_holds:{$productId}", $holdId);
                    $redis->incrby("available_stock:{$productId}", $quantity);
                    $redis->decrby("reserved_stock:{$productId}", $quantity);
                }
                
                $result = $redis->exec();
                
                if ($result !== null) {
                    Log::info("Hold marked as expired", ['hold_id' => $holdId]);
                }
                
                throw new HoldExpiredException($expiresAt);
            }

            $redis->multi();
            
            $redis->hmset($holdKey, [
                'last_accessed_at' => time(),
                'last_accessed_at_iso' => date('c')
            ]);

            $result = $redis->exec();

            if ($result === null) {
                Log::warning("Concurrent modification detected during validation", ['hold_id' => $holdId]);
                throw new ConcurrentModificationException();
            }

            Log::info("Hold validated successfully", [
                'hold_id' => $holdId,
                'product_id' => $productId,
                'quantity' => $quantity,
                'status' => $status
            ]);

            return [
                'product_id' => $productId,
                'quantity' => $quantity,
                'expires_at' => $expiresAt,
                'expires_at_timestamp' => $expiresAtTimestamp,
                'status' => $status,
                'original_hold_data' => $hold
            ];

        } catch (Exception $e) {
            $redis->unwatch();
            throw $e;
        }
    }

    private function createOrderInDatabase(string $holdId): Order
    {
        return DB::transaction(function () use ($holdId) {
            Log::info("Creating order in database", ['hold_id' => $holdId]);

            $order = Order::create([
                'hold_id' => $holdId,
                'state' => 'pending_payment',
                'created_at' => now(),
                'updated_at' => now()
            ]);

            Log::info("Order saved to database", [
                'order_id' => $order->id,
                'hold_id' => $order->hold_id
            ]);

            return $order;
        });
    }

    private function validateHoldSimple(string $holdId): array
    {
        $holdKey = "hold:{$holdId}";
        
        $luaScript = <<<LUA
            local holdKey = KEYS[1]
            local currentTime = tonumber(ARGV[1])
            
            -- Get hold data
            local hold = redis.call('HGETALL', holdKey)
            if #hold == 0 then
                return {error = 'NOT_FOUND'}
            end
            
            -- Convert to table
            local holdData = {}
            for i = 1, #hold, 2 do
                holdData[hold[i]] = hold[i + 1]
            end
            
            -- Check status
            local status = holdData['status'] or 'active'
            if status == 'used' then
                return {error = 'ALREADY_USED'}
            end
            
            if status == 'expired' then
                return {error = 'ALREADY_EXPIRED'}
            end
            
            if status == 'payment_failed' then
                return {error = 'PAYMENT_FAILED'}
            end
            
            -- Check expiration
            local expiresAt = holdData['expires_at_timestamp']
            if expiresAt and tonumber(expiresAt) < currentTime then
                -- Mark as expired
                redis.call('HMSET', holdKey,
                    'status', 'expired',
                    'expired_at', currentTime,
                    'expired_at_iso', ARGV[2]
                )
                
                -- Release stock if product_id exists
                local productId = holdData['product_id']
                local quantity = tonumber(holdData['qty'] or 0)
                if productId and quantity > 0 then
                    redis.call('INCRBY', 'available_stock:' .. productId, quantity)
                    redis.call('DECRBY', 'reserved_stock:' .. productId, quantity)
                    redis.call('SREM', 'product_holds:' .. productId, KEYS[1]:gsub('hold:', ''))
                end
                
                return {error = 'EXPIRED'}
            end
            
            -- Update last accessed
            redis.call('HSET', holdKey, 
                'last_accessed_at', currentTime,
                'last_accessed_at_iso', ARGV[2]
            )
            
            return {
                success = true,
                product_id = holdData['product_id'],
                quantity = tonumber(holdData['qty'] or 0),
                expires_at = holdData['expires_at'],
                expires_at_timestamp = holdData['expires_at_timestamp'],
                status = status
            }
LUA;

        $result = Redis::eval(
            $luaScript,
            1,
            $holdKey,
            time(),
            date('c')
        );

        if (isset($result['error'])) {
            switch ($result['error']) {
                case 'NOT_FOUND':
                    throw new HoldNotFoundException();
                case 'ALREADY_USED':
                    throw new HoldAlreadyUsedException();
                case 'ALREADY_EXPIRED':
                case 'EXPIRED':
                    throw new HoldExpiredException();
                case 'PAYMENT_FAILED':
                    throw new Exception('Hold has previous payment failure');
                default:
                    throw new Exception('Hold validation failed: ' . $result['error']);
            }
        }

        return $result;
    }

    private function checkRedisAvailability(): void
    {
        try {
            Redis::ping();
        } catch (Exception $e) {
            throw new RedisUnavailableException('Redis unavailable');
        }
    }
}