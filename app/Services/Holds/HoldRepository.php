<?php

namespace App\Services\Holds;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

class HoldRepository
{
    private const HOLD_KEY_PREFIX = 'hold:';
    private const PRODUCT_HOLDS_SET_PREFIX = 'product_holds:';
    private const ACTIVE_HOLDS_COUNTER_PREFIX = 'active_holds:';
    private const EXPIRED_HOLDS_SET_PREFIX = 'expired_holds:';
    private const AVAILABLE_STOCK_PREFIX = 'available_stock:';
    private const RESERVED_STOCK_PREFIX = 'reserved_stock:';
    private const STOCK_VERSION_PREFIX = 'stock_version:';
    
    private const BATCH_SIZE = 100;
    private const PIPELINE_CHUNK_SIZE = 50;
    
    // New optimization: Use sorted sets for expiring holds
    private const EXPIRING_HOLDS_ZSET_PREFIX = 'expiring_holds:';
    private const HOLDS_BY_STATUS_PREFIX = 'holds_by_status:';
    
    public function createHold(string $holdId, int $productId, int $quantity, string $status = 'active'): void
    {
        $redis = Redis::connection();
        
        $holdKey = self::HOLD_KEY_PREFIX . $holdId;
        $productHoldsSet = self::PRODUCT_HOLDS_SET_PREFIX . $productId;
        $activeHoldsCounter = self::ACTIVE_HOLDS_COUNTER_PREFIX . $productId;
        $availableStockKey = self::AVAILABLE_STOCK_PREFIX . $productId;
        $reservedStockKey = self::RESERVED_STOCK_PREFIX . $productId;
        $stockVersionKey = self::STOCK_VERSION_PREFIX . $productId;
        
        $now = now();
        $expiresAt = $now->copy()->addMinutes(2);
        $expiresTimestamp = $expiresAt->timestamp;
        
        $holdData = [
            'hold_id' => $holdId,
            'product_id' => $productId,
            'qty' => $quantity,
            'status' => $status,
            'created_at' => $now->toISOString(),
            'expires_at' => $expiresAt->toISOString(),
            'expires_at_timestamp' => $expiresTimestamp,
            'updated_at' => $now->toISOString(),
        ];
        
        try {
            $redis->watch([
                $holdKey, 
                $productHoldsSet, 
                $activeHoldsCounter, 
                $availableStockKey, 
                $reservedStockKey,
                self::EXPIRING_HOLDS_ZSET_PREFIX . $productId,
                self::HOLDS_BY_STATUS_PREFIX . $status
            ]);
            
            if ($redis->exists($holdKey)) {
                $redis->unwatch();
                throw new \RuntimeException("Hold {$holdId} already exists");
            }
            
            $availableStock = (int) $redis->get($availableStockKey);
            if ($availableStock < $quantity) {
                $redis->unwatch();
                throw new \RuntimeException("Insufficient available stock");
            }
            
            $redis->multi();
            
            // Store hold data
            $redis->hmset($holdKey, $holdData);
            
            // Add to product holds set
            $redis->sadd($productHoldsSet, $holdId);
            
            // Add to expiring holds sorted set (score = expiration timestamp)
            $redis->zadd(self::EXPIRING_HOLDS_ZSET_PREFIX . $productId, $expiresTimestamp, $holdId);
            
            // Add to status set for quick filtering
            $redis->sadd(self::HOLDS_BY_STATUS_PREFIX . $status, $holdId);
            
            if ($status === 'active') {
                $redis->incrby($activeHoldsCounter, $quantity);
                $redis->decrby($availableStockKey, $quantity);
                $redis->incrby($reservedStockKey, $quantity);
                $redis->incr($stockVersionKey);
            }
            
            $result = $redis->exec();
            
            if ($result === null) {
                throw new \RuntimeException('Failed to create hold due to concurrent modification');
            }
            
            Log::debug('Hold created successfully', [
                'hold_id' => $holdId,
                'product_id' => $productId,
                'quantity' => $quantity
            ]);
            
        } catch (Exception $e) {
            Log::error('Failed to create hold', [
                'hold_id' => $holdId,
                'product_id' => $productId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
    
    // Optimized: Get multiple holds in batch
    public function getHolds(array $holdIds): array
    {
        if (empty($holdIds)) {
            return [];
        }
        
        $redis = Redis::connection();
        $holds = [];
        
        try {
            // Use pipeline to fetch all holds in one network roundtrip
            $pipeline = $redis->pipeline();
            
            foreach ($holdIds as $holdId) {
                $pipeline->hgetall(self::HOLD_KEY_PREFIX . $holdId);
            }
            
            $results = $pipeline->exec();
            
            foreach ($results as $index => $data) {
                if (!empty($data)) {
                    $holds[$holdIds[$index]] = $this->normalizeHoldData($data);
                }
            }
            
        } catch (Exception $e) {
            Log::error('Failed to get holds in batch', [
                'hold_ids_count' => count($holdIds),
                'error' => $e->getMessage()
            ]);
        }
        
        return $holds;
    }
    
    // Keep single hold getter for compatibility
    public function getHold(string $holdId, bool $withLock = false): ?array
    {
        $redis = Redis::connection();
        $holdKey = self::HOLD_KEY_PREFIX . $holdId;
        
        try {
            if ($withLock) {
                $redis->watch([$holdKey]);
            }
            
            $data = $redis->hgetall($holdKey);
            
            if (empty($data)) {
                if ($withLock) {
                    $redis->unwatch();
                }
                return null;
            }
            
            return $this->normalizeHoldData($data);
            
        } catch (Exception $e) {
            Log::error('Failed to get hold', [
                'hold_id' => $holdId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    // Helper method to normalize hold data
    private function normalizeHoldData(array $data): array
    {
        if (isset($data['qty'])) {
            $data['qty'] = (int) $data['qty'];
        }
        if (isset($data['product_id'])) {
            $data['product_id'] = (int) $data['product_id'];
        }
        if (isset($data['expires_at_timestamp'])) {
            $data['expires_at_timestamp'] = (int) $data['expires_at_timestamp'];
        }
        
        return $data;
    }
    
    // OPTIMIZED VERSION: Find expired holds using sorted sets
    public function findExpiredHolds(int $limit = self::BATCH_SIZE): array
    {
        $expiredHolds = [];
        $now = time();
        
        try {
            // Get all products with expiring holds
            $expiringZsetKeys = Redis::keys(self::EXPIRING_HOLDS_ZSET_PREFIX . '*');
            
            if (empty($expiringZsetKeys)) {
                return [];
            }
            
            foreach ($expiringZsetKeys as $zsetKey) {
                // Extract product ID from key
                preg_match('/expiring_holds:(\d+)/', $zsetKey, $matches);
                if (!isset($matches[1])) {
                    continue;
                }
                
                $productId = (int) $matches[1];
                
                // Get expired hold IDs using ZRANGEBYSCORE (O(log N) complexity)
                $expiredHoldIds = Redis::zrangebyscore($zsetKey, 0, $now, [
                    'limit' => [0, $limit]
                ]);
                
                if (empty($expiredHoldIds)) {
                    continue;
                }
                
                // Fetch all hold data in one batch
                $holdsData = $this->getHolds($expiredHoldIds);
                
                foreach ($holdsData as $holdId => $holdData) {
                    // Only include active holds that are expired
                    if (($holdData['status'] ?? '') === 'active') {
                        $expiredHolds[] = [
                            'hold_id' => $holdId,
                            'product_id' => $productId,
                            'quantity' => (int) ($holdData['qty'] ?? 0),
                            'expires_at_timestamp' => (int) ($holdData['expires_at_timestamp'] ?? 0),
                            'data' => $holdData
                        ];
                        
                        if (count($expiredHolds) >= $limit) {
                            return $expiredHolds;
                        }
                    }
                }
            }
            
            return $expiredHolds;
            
        } catch (Exception $e) {
            Log::error('Failed to find expired holds', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }
    
    // Alternative approach: Use scan for large datasets
    public function findExpiredHoldsWithScan(int $limit = self::BATCH_SIZE): array
    {
        $expiredHolds = [];
        $now = time();
        
        try {
            $cursor = 0;
            $pattern = self::HOLD_KEY_PREFIX . '*';
            
            do {
                // Scan for hold keys in batches
                $result = Redis::scan($cursor, [
                    'match' => $pattern,
                    'count' => 50
                ]);
                
                $cursor = $result[0];
                $keys = $result[1];
                
                if (!empty($keys)) {
                    // Use pipeline to fetch all hold data in one go
                    $pipeline = Redis::pipeline();
                    foreach ($keys as $key) {
                        $pipeline->hgetall($key);
                    }
                    $holdsData = $pipeline->exec();
                    
                    foreach ($holdsData as $index => $data) {
                        if (empty($data)) {
                            continue;
                        }
                        
                        // Check if hold is active and expired
                        if (($data['status'] ?? '') === 'active') {
                            $expiresAt = (int) ($data['expires_at_timestamp'] ?? 0);
                            
                            if ($expiresAt > 0 && $now > $expiresAt) {
                                $holdId = str_replace(self::HOLD_KEY_PREFIX, '', $keys[$index]);
                                $expiredHolds[] = [
                                    'hold_id' => $holdId,
                                    'product_id' => (int) ($data['product_id'] ?? 0),
                                    'quantity' => (int) ($data['qty'] ?? 0),
                                    'expires_at_timestamp' => $expiresAt,
                                    'data' => $data
                                ];
                                
                                if (count($expiredHolds) >= $limit) {
                                    return $expiredHolds;
                                }
                            }
                        }
                    }
                }
            } while ($cursor != 0);
            
            return $expiredHolds;
            
        } catch (Exception $e) {
            Log::error('Failed to find expired holds with scan', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    // Bulk update for hold status with optimizations
    public function updateHoldStatus(
        string $holdId, 
        string $newStatus, 
        array $additionalData = [],
        ?int $oldQuantity = null
    ): bool
    {
        $redis = Redis::connection();
        $holdKey = self::HOLD_KEY_PREFIX . $holdId;
        $availableStockPrefix = self::AVAILABLE_STOCK_PREFIX;
        $reservedStockPrefix = self::RESERVED_STOCK_PREFIX;
        $stockVersionPrefix = self::STOCK_VERSION_PREFIX;
        
        try {
            $redis->watch([$holdKey]);
            
            $currentHold = $redis->hgetall($holdKey);
            
            if (empty($currentHold)) {
                $redis->unwatch();
                return false;
            }
            
            $currentStatus = $currentHold['status'] ?? null;
            $productId = (int) ($currentHold['product_id'] ?? 0);
            $quantity = (int) ($currentHold['qty'] ?? 0);
            
            if ($oldQuantity !== null && $oldQuantity !== $quantity) {
                $redis->unwatch();
                throw new \RuntimeException('Hold quantity mismatch');
            }
            
            $availableStockKey = $availableStockPrefix . $productId;
            $reservedStockKey = $reservedStockPrefix . $productId;
            $stockVersionKey = $stockVersionPrefix . $productId;
            
            $redis->watch([$availableStockKey, $reservedStockKey]);
            
            $redis->multi();
            
            $updateData = array_merge(
                [
                    'status' => $newStatus,
                    'updated_at' => now()->toISOString(),
                    'status_changed_at' => now()->toISOString(),
                ],
                $additionalData
            );
            
            $redis->hmset($holdKey, $updateData);
            
            // Update status sets
            if ($currentStatus !== $newStatus) {
                $redis->srem(self::HOLDS_BY_STATUS_PREFIX . $currentStatus, $holdId);
                $redis->sadd(self::HOLDS_BY_STATUS_PREFIX . $newStatus, $holdId);
                
                // Remove from expiring set if not active
                if ($currentStatus === 'active' && $newStatus !== 'active') {
                    $redis->zrem(self::EXPIRING_HOLDS_ZSET_PREFIX . $productId, $holdId);
                }
            }
            
            if ($currentStatus === 'active' && $newStatus !== 'active') {
                $redis->incrby($availableStockKey, $quantity);
                $redis->decrby($reservedStockKey, $quantity);
                $redis->incr($stockVersionKey);
                
                $activeHoldsCounter = self::ACTIVE_HOLDS_COUNTER_PREFIX . $productId;
                $redis->decrby($activeHoldsCounter, $quantity);
                
                if ($newStatus === 'expired') {
                    $redis->sadd(self::EXPIRED_HOLDS_SET_PREFIX . date('Y-m-d'), $holdId);
                }
            } elseif ($currentStatus !== 'active' && $newStatus === 'active') {
                $availableStock = (int) $redis->get($availableStockKey);
                if ($availableStock < $quantity) {
                    $redis->discard();
                    throw new \RuntimeException("Cannot activate hold - insufficient available stock");
                }
                
                $redis->decrby($availableStockKey, $quantity);
                $redis->incrby($reservedStockKey, $quantity);
                $redis->incr($stockVersionKey);
                
                $activeHoldsCounter = self::ACTIVE_HOLDS_COUNTER_PREFIX . $productId;
                $redis->incrby($activeHoldsCounter, $quantity);
                
                // Add back to expiring set
                $expiresAt = (int) ($currentHold['expires_at_timestamp'] ?? now()->addMinutes(2)->timestamp);
                $redis->zadd(self::EXPIRING_HOLDS_ZSET_PREFIX . $productId, $expiresAt, $holdId);
            }
            
            $result = $redis->exec();
            
            if ($result === null) {
                throw new \RuntimeException('Failed to update hold status due to concurrent modification');
            }
            
            return true;
            
        } catch (Exception $e) {
            Log::error('Failed to update hold status', [
                'hold_id' => $holdId,
                'new_status' => $newStatus,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    // Optimized: Get hold statistics with batch operations
    public function getHoldStatistics(): array
    {
        try {
            $productHoldKeys = Redis::keys(self::PRODUCT_HOLDS_SET_PREFIX . '*');
            
            $stats = [
                'total_products_with_holds' => count($productHoldKeys),
                'total_active_holds' => 0,
                'total_expired_holds' => 0,
                'total_holds_by_product' => [],
                'total_quantity_reserved' => 0,
            ];
            
            if (empty($productHoldKeys)) {
                return $stats;
            }
            
            // Get active holds count from counter keys
            $activeHoldKeys = Redis::keys(self::ACTIVE_HOLDS_COUNTER_PREFIX . '*');
            foreach ($activeHoldKeys as $key) {
                preg_match('/active_holds:(\d+)/', $key, $matches);
                if (isset($matches[1])) {
                    $productId = (int) $matches[1];
                    $activeQuantity = (int) Redis::get($key);
                    $stats['total_quantity_reserved'] += $activeQuantity;
                }
            }
            
            // Sample a few products for detailed stats
            $sampleProducts = array_slice($productHoldKeys, 0, min(5, count($productHoldKeys)));
            
            foreach ($sampleProducts as $setKey) {
                preg_match('/product_holds:(\d+)/', $setKey, $matches);
                if (isset($matches[1])) {
                    $productId = (int) $matches[1];
                    $holdIds = Redis::smembers($setKey);
                    
                    if (!empty($holdIds)) {
                        // Use batch get for sample holds
                        $sampleSize = min(10, count($holdIds));
                        $sampleHoldIds = array_slice($holdIds, 0, $sampleSize);
                        $sampleHolds = $this->getHolds($sampleHoldIds);
                        
                        $productStats = [
                            'product_id' => $productId,
                            'total_holds' => count($holdIds),
                            'active_holds' => 0,
                            'expired_holds' => 0,
                            'reserved_quantity' => 0,
                        ];
                        
                        foreach ($sampleHolds as $hold) {
                            if ($hold['status'] === 'active') {
                                $productStats['active_holds']++;
                                $productStats['reserved_quantity'] += (int) $hold['qty'];
                            } elseif ($hold['status'] === 'expired') {
                                $productStats['expired_holds']++;
                            }
                        }
                        
                        // Estimate based on sample
                        if ($sampleSize > 0) {
                            $productStats['active_holds'] = round(
                                ($productStats['active_holds'] / $sampleSize) * count($holdIds)
                            );
                            $productStats['expired_holds'] = round(
                                ($productStats['expired_holds'] / $sampleSize) * count($holdIds)
                            );
                        }
                        
                        $stats['total_active_holds'] += $productStats['active_holds'];
                        $stats['total_expired_holds'] += $productStats['expired_holds'];
                        $stats['total_holds_by_product'][$productId] = $productStats;
                    }
                }
            }
            
            return $stats;
            
        } catch (Exception $e) {
            Log::error('Failed to get hold statistics', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    public function expireOldHoldsBatch(int $batchSize = 100): array
    {
        $results = [
            'expired' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        try {
            $expiredHolds = $this->findExpiredHolds($batchSize);
            
            foreach ($expiredHolds as $holdInfo) {
                try {
                    $success = $this->updateHoldStatus(
                        $holdInfo['hold_id'],
                        'expired',
                        ['batch_expired_at' => now()->toISOString()]
                    );
                    
                    if ($success) {
                        $results['expired']++;
                    } else {
                        $results['failed']++;
                    }
                } catch (Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = $e->getMessage();
                    Log::warning('Failed to expire hold in batch', [
                        'hold_id' => $holdInfo['hold_id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
        } catch (Exception $e) {
            Log::error('Failed to expire holds in batch', [
                'error' => $e->getMessage()
            ]);
        }
        
        return $results;
    }
    
}