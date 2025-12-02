<?php

namespace App\Services\Holds;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use App\Models\Product;
use App\Exceptions\HoldNotFoundException;
use App\Exceptions\InvalidHoldException;
use App\Exceptions\HoldNotExpiredException;
use App\Exceptions\ConcurrentModificationException;
use Exception;

class HoldManagementService
{
    private const MAX_RETRY_ATTEMPTS = 3;
    private const RETRY_DELAY_MS = 100;
    private const BATCH_SIZE = 50;

    private HoldRepository $holdRepository;

    public function __construct(HoldRepository $holdRepository)
    {
        $this->holdRepository = $holdRepository;
    }

    /**
     * Release a hold (optimized with Lua script)
     */
    public function releaseHold(string $holdId): array
    {
        $hold = $this->getValidHold($holdId);
        
        for ($attempt = 1; $attempt <= self::MAX_RETRY_ATTEMPTS; $attempt++) {
            try {
                // Use Lua script for atomic operation without WATCH overhead
                return $this->releaseHoldWithLua($holdId, $hold);
            } catch (ConcurrentModificationException $e) {
                if ($attempt < self::MAX_RETRY_ATTEMPTS) {
                    usleep(self::RETRY_DELAY_MS * 1000 * $attempt);
                    continue;
                }
                throw new Exception('Failed to release hold after retries: ' . $e->getMessage());
            } catch (Exception $e) {
                Log::error("Hold release attempt $attempt failed", [
                    'hold_id' => $holdId,
                    'error' => $e->getMessage()
                ]);
                
                if ($attempt === self::MAX_RETRY_ATTEMPTS) {
                    throw $e;
                }
            }
        }

        throw new Exception('Failed to release hold');
    }

    /**
     * Expire a hold (optimized with Lua script)
     */
    public function expireHold(string $holdId): array
    {
        $hold = $this->getValidHold($holdId);
        $this->validateHoldExpired($hold);

        for ($attempt = 1; $attempt <= self::MAX_RETRY_ATTEMPTS; $attempt++) {
            try {
                // Use Lua script for atomic operation
                return $this->expireHoldWithLua($holdId, $hold);
            } catch (ConcurrentModificationException $e) {
                if ($attempt < self::MAX_RETRY_ATTEMPTS) {
                    usleep(self::RETRY_DELAY_MS * 1000 * $attempt);
                    continue;
                }
                throw new Exception('Failed to expire hold after retries: ' . $e->getMessage());
            } catch (Exception $e) {
                Log::error("Hold expire attempt $attempt failed", [
                    'hold_id' => $holdId,
                    'error' => $e->getMessage()
                ]);
                
                if ($attempt === self::MAX_RETRY_ATTEMPTS) {
                    throw $e;
                }
            }
        }

        throw new Exception('Failed to expire hold');
    }

    /**
     * Cleanup expired holds with BATCH OPTIMIZATION
     */
    public function cleanupExpiredHolds(int $batchSize = 100): array
    {
        $stats = [
            'expired_count' => 0,
            'released_stock' => 0,
            'errors' => [],
            'batches_processed' => 0
        ];

        // Process in smaller batches for better performance
        $processed = 0;
        
        while ($processed < $batchSize) {
            $remaining = $batchSize - $processed;
            $currentBatchSize = min(self::BATCH_SIZE, $remaining);
            
            if ($currentBatchSize <= 0) break;
            
            $batchResult = $this->processExpiredBatch($currentBatchSize);
            
            $stats['expired_count'] += $batchResult['expired_count'];
            $stats['released_stock'] += $batchResult['released_stock'];
            $stats['errors'] = array_merge($stats['errors'], $batchResult['errors']);
            $stats['batches_processed']++;
            
            $processed += $currentBatchSize;
            
            // If we didn't get a full batch, there are no more expired holds
            if ($batchResult['expired_count'] < $currentBatchSize) {
                break;
            }
        }

        Log::info("Expired holds cleanup completed", $stats);

        return $stats;
    }

    /**
     * Process a batch of expired holds OPTIMIZED
     */
    private function processExpiredBatch(int $batchSize): array
    {
        $expiredCount = 0;
        $releasedStock = 0;
        $errors = [];

        // Get expired holds in batch
        $expiredHolds = $this->holdRepository->findExpiredHolds($batchSize);
        
        if (empty($expiredHolds)) {
            return compact('expiredCount', 'releasedStock', 'errors');
        }

        // Group holds by product for batch operations
        $holdsByProduct = [];
        foreach ($expiredHolds as $holdInfo) {
            $productId = $holdInfo['product_id'] ?? null;
            $holdId = $holdInfo['hold_id'] ?? null;
            
            if (!$productId || !$holdId) continue;
            
            if (!isset($holdsByProduct[$productId])) {
                $holdsByProduct[$productId] = [];
            }
            
            $holdsByProduct[$productId][] = [
                'hold_id' => $holdId,
                'quantity' => (int) ($holdInfo['quantity'] ?? 0)
            ];
        }

        // Process holds by product for better batching
        foreach ($holdsByProduct as $productId => $productHolds) {
            if (count($productHolds) > 1) {
                // Use bulk expiration for multiple holds on same product
                $bulkResult = $this->bulkExpireHolds($productHolds, $productId);
                $expiredCount += $bulkResult['expired_count'];
                $releasedStock += $bulkResult['released_stock'];
                $errors = array_merge($errors, $bulkResult['errors']);
            } else {
                // Single hold - use individual expiration
                $hold = $productHolds[0];
                try {
                    $result = $this->expireHold($hold['hold_id']);
                    $expiredCount++;
                    $releasedStock += $hold['quantity'];
                } catch (HoldNotExpiredException $e) {
                    Log::debug('Hold no longer expired, skipping', ['hold_id' => $hold['hold_id']]);
                    continue;
                } catch (Exception $e) {
                    $errors[] = [
                        'hold_id' => $hold['hold_id'],
                        'error' => $e->getMessage()
                    ];
                    Log::error("Failed to expire hold during cleanup", [
                        'hold_id' => $hold['hold_id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return compact('expiredCount', 'releasedStock', 'errors');
    }

    /**
     * Bulk expire multiple holds for same product (optimized)
     */
    private function bulkExpireHolds(array $holds, int $productId): array
    {
        $expiredCount = 0;
        $releasedStock = 0;
        $errors = [];

        try {
            // Use Lua script for bulk atomic operation
            $script = "
                local expired_count = 0
                local released_stock = 0
                local errors = {}
                
                for i, hold_id in ipairs(KEYS) do
                    local hold_key = 'hold:' .. hold_id
                    local hold_data = redis.call('HGETALL', hold_key)
                    
                    if #hold_data == 0 then
                        table.insert(errors, 'Hold ' .. hold_id .. ' not found')
                        goto continue
                    end
                    
                    -- Convert array to table
                    local hold = {}
                    for j = 1, #hold_data, 2 do
                        hold[hold_data[j]] = hold_data[j+1]
                    end
                    
                    if hold['status'] ~= 'active' then
                        table.insert(errors, 'Hold ' .. hold_id .. ' not active: ' .. (hold['status'] or 'unknown'))
                        goto continue
                    end
                    
                    local quantity = tonumber(hold['qty'] or 0)
                    
                    -- Check expiration
                    local expires_at = tonumber(hold['expires_at_timestamp'] or 0)
                    if expires_at == 0 or expires_at > ARGV[1] then
                        table.insert(errors, 'Hold ' .. hold_id .. ' not expired')
                        goto continue
                    end
                    
                    -- Release stock
                    redis.call('INCRBY', 'available_stock:' .. ARGV[2], quantity)
                    redis.call('DECRBY', 'reserved_stock:' .. ARGV[2], quantity)
                    redis.call('INCR', 'stock_version:' .. ARGV[2])
                    redis.call('DECRBY', 'active_holds:' .. ARGV[2], quantity)
                    
                    -- Delete hold
                    redis.call('DEL', hold_key)
                    redis.call('SREM', 'product_holds:' .. ARGV[2], hold_id)
                    
                    expired_count = expired_count + 1
                    released_stock = released_stock + quantity
                    
                    ::continue::
                end
                
                return {expired_count, released_stock, table.concat(errors, ';')}
            ";
            
            $holdIds = array_column($holds, 'hold_id');
            $result = Redis::eval(
                $script,
                count($holdIds),
                ...array_merge($holdIds, [time(), $productId])
            );
            
            if ($result && is_array($result)) {
                $expiredCount = $result[0];
                $releasedStock = $result[1];
                $errorStr = $result[2] ?? '';
                
                if ($errorStr) {
                    foreach (explode(';', $errorStr) as $error) {
                        if ($error) {
                            $errors[] = ['bulk_error' => $error];
                        }
                    }
                }
            }
            
        } catch (Exception $e) {
            $errors[] = [
                'bulk_error' => 'Bulk expiration failed: ' . $e->getMessage(),
                'product_id' => $productId,
                'hold_count' => count($holds)
            ];
            Log::error('Bulk expiration failed', [
                'product_id' => $productId,
                'hold_count' => count($holds),
                'error' => $e->getMessage()
            ]);
        }

        return compact('expiredCount', 'releasedStock', 'errors');
    }

    /**
     * Release hold using LUA script (atomic, no WATCH overhead)
     */
    private function releaseHoldWithLua(string $holdId, array $hold): array
    {
        $productId = $hold['product_id'];
        $quantity = (int) $hold['qty'];

        $script = "
            local hold_key = KEYS[1]
            local product_id = ARGV[1]
            local quantity = tonumber(ARGV[2])
            
            local hold_data = redis.call('HGETALL', hold_key)
            
            if #hold_data == 0 then
                return {false, 'Hold not found'}
            end
            
            -- Convert array to table
            local hold = {}
            for i = 1, #hold_data, 2 do
                hold[hold_data[i]] = hold_data[i+1]
            end
            
            if hold['status'] ~= 'active' then
                return {false, 'Hold not active: ' .. (hold['status'] or 'unknown')}
            end
            
            -- Get current reserved stock
            local current_reserved = tonumber(redis.call('GET', 'reserved_stock:' .. product_id) or 0)
            
            if current_reserved < quantity then
                return {false, 'Insufficient reserved stock: have ' .. current_reserved .. ', need ' .. quantity}
            end
            
            -- Perform operations atomically
            redis.call('INCRBY', 'available_stock:' .. product_id, quantity)
            redis.call('DECRBY', 'reserved_stock:' .. product_id, quantity)
            redis.call('INCR', 'stock_version:' .. product_id)
            redis.call('DECRBY', 'active_holds:' .. product_id, quantity)
            
            -- Delete hold
            redis.call('DEL', hold_key)
            redis.call('SREM', 'product_holds:' .. product_id, ARGV[3])
            
            local new_available = tonumber(redis.call('GET', 'available_stock:' .. product_id) or 0)
            local new_reserved = tonumber(redis.call('GET', 'reserved_stock:' .. product_id) or 0)
            
            return {true, product_id, quantity, new_available, new_reserved}
        ";

        $result = Redis::eval($script, 1, "hold:{$holdId}", $productId, $quantity, $holdId);
        
        if (!$result || !is_array($result) || !$result[0]) {
            throw new InvalidHoldException($result[2] ?? 'Failed to release hold');
        }

        return [
            'product_id' => $result[1],
            'released_qty' => $result[2],
            'new_available_stock' => $result[3],
            'new_reserved_stock' => $result[4],
            'hold_deleted' => true
        ];
    }

    /**
     * Expire hold using LUA script (atomic, no WATCH overhead)
     */
    private function expireHoldWithLua(string $holdId, array $hold): array
    {
        $productId = $hold['product_id'];
        $quantity = (int) $hold['qty'];

        $script = "
            local hold_key = KEYS[1]
            local product_id = ARGV[1]
            local quantity = tonumber(ARGV[2])
            local current_time = tonumber(ARGV[3])
            
            local hold_data = redis.call('HGETALL', hold_key)
            
            if #hold_data == 0 then
                return {false, 'Hold not found'}
            end
            
            -- Convert array to table
            local hold = {}
            for i = 1, #hold_data, 2 do
                hold[hold_data[i]] = hold_data[i+1]
            end
            
            if hold['status'] ~= 'active' then
                return {false, 'Hold not active: ' .. (hold['status'] or 'unknown')}
            end
            
            -- Check expiration
            local expires_at = tonumber(hold['expires_at_timestamp'] or 0)
            if expires_at == 0 or expires_at > current_time then
                return {false, 'Hold not expired'}
            end
            
            -- Get current reserved stock
            local current_reserved = tonumber(redis.call('GET', 'reserved_stock:' .. product_id) or 0)
            
            if current_reserved < quantity then
                return {false, 'Insufficient reserved stock: have ' .. current_reserved .. ', need ' .. quantity}
            end
            
            -- Perform operations atomically
            redis.call('INCRBY', 'available_stock:' .. product_id, quantity)
            redis.call('DECRBY', 'reserved_stock:' .. product_id, quantity)
            redis.call('INCR', 'stock_version:' .. product_id)
            redis.call('DECRBY', 'active_holds:' .. product_id, quantity)
            
            -- Delete hold
            redis.call('DEL', hold_key)
            redis.call('SREM', 'product_holds:' .. product_id, ARGV[4])
            
            local new_available = tonumber(redis.call('GET', 'available_stock:' .. product_id) or 0)
            local new_reserved = tonumber(redis.call('GET', 'reserved_stock:' .. product_id) or 0)
            
            return {true, product_id, quantity, new_available, new_reserved}
        ";

        $result = Redis::eval($script, 1, "hold:{$holdId}", $productId, $quantity, time(), $holdId);
        
        if (!$result || !is_array($result) || !$result[0]) {
            $error = $result[2] ?? 'Failed to expire hold';
            
            if (strpos($error, 'not expired') !== false) {
                throw new HoldNotExpiredException();
            }
            
            throw new InvalidHoldException($error);
        }

        return [
            'product_id' => $result[1],
            'expired_qty' => $result[2],
            'new_available_stock' => $result[3],
            'new_reserved_stock' => $result[4],
            'hold_deleted' => true
        ];
    }

    /**
     * Get all keys involved in hold transaction
     */
    private function getHoldTransactionKeys(string $holdId, int $productId): array
    {
        return [
            "hold:{$holdId}",
            "available_stock:{$productId}",
            "reserved_stock:{$productId}",
            "stock_version:{$productId}",
            "active_holds:{$productId}",
            "product_holds:{$productId}"
        ];
    }

    /**
     * Get and validate hold
     */
    private function getValidHold(string $holdId): array
    {
        $hold = $this->holdRepository->getHold($holdId);
        
        if (!$hold) {
            throw new HoldNotFoundException();
        }

        if (empty($hold['product_id']) || empty($hold['qty'])) {
            throw new InvalidHoldException('Invalid hold data');
        }

        return $hold;
    }

    /**
     * Check if hold should be expired (application-level expiration check)
     * ADDED BACK for test compatibility
     */
    private function shouldExpireHold(?array $hold): bool
    {
        if (!$hold || ($hold['status'] ?? '') !== 'active') {
            return false;
        }

        $expiresAt = $hold['expires_at_timestamp'] ?? 0;
        return $expiresAt > 0 && time() > $expiresAt;
    }

    /**
     * Validate hold is expired (application-level expiration check)
     */
    private function validateHoldExpired(array $hold): void
    {
        $expiresAt = $hold['expires_at_timestamp'] ?? 0;
        
        if ($expiresAt === 0) {
            throw new InvalidHoldException('Hold has no expiration');
        }

        if (time() < $expiresAt) {
            $secondsRemaining = $expiresAt - time();
            throw new HoldNotExpiredException(
                $hold['expires_at'] ?? null,
                $secondsRemaining
            );
        }
    }
}