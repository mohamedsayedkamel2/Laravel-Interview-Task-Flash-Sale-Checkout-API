<?php

namespace App\Services\Holds;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use App\Services\Holds\HoldRepository;
use App\Exceptions\HoldNotFoundException;
use App\Exceptions\HoldExpiredException;
use App\Exceptions\HoldAlreadyUsedException;
use App\Exceptions\ConcurrentModificationException;
use Exception;

class HoldValidationService
{
    private HoldRepository $holdRepository;
    
    private const MAX_RETRY_ATTEMPTS = 3;
    private const RETRY_DELAY_MS = 100;

    public function __construct(HoldRepository $holdRepository)
    {
        $this->holdRepository = $holdRepository;
    }

    /**
     * Validate a hold
     */
    public function validateHold(string $holdId): array
    {
        $hold = $this->holdRepository->getHold($holdId);
        
        if (!$hold) {
            throw new HoldNotFoundException();
        }

        $isExpiredTimestamp = isset($hold['expires_at_timestamp']) && 
                            time() > (int) $hold['expires_at_timestamp'];
        $isUsed = isset($hold['status']) && $hold['status'] === 'used';
        $isExpiredStatus = isset($hold['status']) && $hold['status'] === 'expired';
        $isValid = !$isExpiredTimestamp && !$isUsed && !$isExpiredStatus;

        $productId = isset($hold['product_id']) ? (int) $hold['product_id'] : null;
        $quantity = isset($hold['qty']) ? (int) $hold['qty'] : 0;

        return [
            'valid' => $isValid,
            'hold_id' => $holdId,
            'product_id' => $productId,
            'quantity' => $quantity,
            'expires_at' => $hold['expires_at'] ?? null,
            'status' => $hold['status'] ?? 'active',
            'is_expired' => $isExpiredTimestamp || $isExpiredStatus,
            'is_used' => $isUsed,
            'current_time' => date('c'),
            'expires_at_timestamp' => $hold['expires_at_timestamp'] ?? null,
            'time_remaining' => isset($hold['expires_at_timestamp']) 
                ? max(0, (int) $hold['expires_at_timestamp'] - time())
                : null
        ];
    }

    /**
     * Expire a hold manually
     */
    public function expireHold(string $holdId): array
    {
        return $this->attemptExpireHold($holdId);
    }

    /**
     * Debug hold data
     */
    public function debugHold(string $holdId): array
    {
        $redis = Redis::connection();
        $holdKey = "hold:{$holdId}";
        
        $hold = $redis->hgetall($holdKey);
        
        if (empty($hold)) {
            throw new Exception('Hold not found in Redis');
        }
        
        return [
            'hold_data' => $hold,
            'keys' => array_keys($hold),
            'product_id' => $hold['product_id'] ?? 'NOT SET',
            'qty' => $hold['qty'] ?? 'NOT SET',
            'status' => $hold['status'] ?? 'NOT SET',
            'type_product_id' => gettype($hold['product_id'] ?? null),
            'type_qty' => gettype($hold['qty'] ?? null),
        ];
    }

    /**
     * Attempt to expire hold using Redis transactions
     */
    private function attemptExpireHold(string $holdId): array
    {
        for ($attempt = 1; $attempt <= self::MAX_RETRY_ATTEMPTS; $attempt++) {
            try {
                return $this->executeHoldExpirationTransaction($holdId);
            } catch (ConcurrentModificationException $e) {
                if ($attempt < self::MAX_RETRY_ATTEMPTS) {
                    usleep(self::RETRY_DELAY_MS * 1000 * $attempt);
                    continue;
                }
                throw new Exception('Failed to expire hold after retries');
            } catch (Exception $e) {
                Log::error("Hold expiration attempt $attempt failed", [
                    'hold_id' => $holdId,
                    'error' => $e->getMessage()
                ]);
                
                if ($attempt === self::MAX_RETRY_ATTEMPTS) {
                    throw $e;
                }
            }
        }

        throw new Exception('Failed to expire hold after all retries');
    }

    /**
     * Execute hold expiration using Redis transactions
     */
    private function executeHoldExpirationTransaction(string $holdId): array
    {
        $redis = Redis::connection();
        $holdKey = "hold:{$holdId}";

        $redis->watch([$holdKey]);

        try {
            $hold = $redis->hgetall($holdKey);
            
            if (empty($hold)) {
                $redis->unwatch();
                throw new HoldNotFoundException();
            }

            $productId = isset($hold['product_id']) ? (int) $hold['product_id'] : null;
            $quantity = isset($hold['qty']) ? (int) $hold['qty'] : 0;
            $status = $hold['status'] ?? 'active';

            if ($status === 'used') {
                $redis->unwatch();
                throw new HoldAlreadyUsedException();
            }

            if ($status === 'expired') {
                $redis->unwatch();
                // Already expired, return success
                return [
                    'product_id' => $productId,
                    'released_quantity' => 0,
                    'already_expired' => true
                ];
            }

            $redis->multi();
            
            // Update hold status
            $redis->hmset($holdKey, [
                'status' => 'expired',
                'expired_at' => time(),
                'expired_at_iso' => date('c')
            ]);

            // Remove from product holds set
            if ($productId) {
                $redis->srem("product_holds:{$productId}", $holdId);
            }

            // Update stock (release reserved stock back to available)
            if ($productId && $quantity > 0) {
                $this->releaseStockInTransaction($redis, $productId, $quantity);
            }

            $result = $redis->exec();

            if ($result === null) {
                throw new ConcurrentModificationException();
            }

            return [
                'product_id' => $productId,
                'released_quantity' => $quantity
            ];

        } catch (Exception $e) {
            $redis->unwatch();
            throw $e;
        }
    }

    /**
     * Release stock within a Redis transaction
     */
    private function releaseStockInTransaction($redis, ?int $productId, int $quantity): void
    {
        if (!$productId || $quantity <= 0) {
            return;
        }
        
        $redis->incrby("available_stock:{$productId}", $quantity);
        $redis->decrby("reserved_stock:{$productId}", $quantity);
        $redis->incr("stock_version:{$productId}");
        $redis->decrby("active_holds:{$productId}", $quantity);
    }
}