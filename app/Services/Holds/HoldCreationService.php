<?php

namespace App\Services\Holds;

use App\Models\Product;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use App\Services\Stock\StockService;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\RedisUnavailableException;
use App\Exceptions\ConcurrentModificationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Exception;

class HoldCreationService
{
    private const MAX_RETRY_ATTEMPTS = 3;
    private const RETRY_DELAY_MS = 100;
    private const HOLD_EXPIRY_MINUTES = 2; // Hold expires after 2 minutes

    private StockService $stockService;
    private HoldRepository $holdRepository;

    public function __construct(StockService $stockService, HoldRepository $holdRepository)
    {
        $this->stockService = $stockService;
        $this->holdRepository = $holdRepository;
    }

    /**
     * Create a hold with thread-safe atomic operations (no Redis TTL)
     */
    public function createHold(int $productId, int $quantity): array
    {
        $this->validateProduct($productId);
        $this->checkRedisAvailability();

        $holdId = Str::uuid()->toString();
        $createdAt = Carbon::now()->toISOString();
        $expiresAt = Carbon::now()->addMinutes(self::HOLD_EXPIRY_MINUTES)->toISOString();
        $expiresAtTimestamp = Carbon::now()->addMinutes(self::HOLD_EXPIRY_MINUTES)->timestamp;

        // Retry logic for transaction conflicts
        for ($attempt = 1; $attempt <= self::MAX_RETRY_ATTEMPTS; $attempt++) {
            try {
                $result = $this->attemptCreateHold(
                    $productId, 
                    $quantity, 
                    $holdId, 
                    $createdAt, 
                    $expiresAt, 
                    $expiresAtTimestamp
                );

                if ($result !== null) {
                    return $this->formatSuccessResponse($holdId, $expiresAt, $productId, $quantity, $result);
                }

                // Transaction conflict, retry with backoff
                Log::debug("Transaction conflict, retry attempt $attempt", [
                    'product_id' => $productId,
                    'hold_id' => $holdId
                ]);
                
                usleep(self::RETRY_DELAY_MS * 1000 * $attempt);
                
            } catch (InsufficientStockException $e) {
                throw $e;
            } catch (Exception $e) {
                Log::error("Hold creation attempt $attempt failed", [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'hold_id' => $holdId,
                    'error' => $e->getMessage()
                ]);

                if ($attempt === self::MAX_RETRY_ATTEMPTS) {
                    throw new Exception('Failed to create hold after all retries: ' . $e->getMessage());
                }
            }
        }

        throw new Exception('Failed to create hold after all retries');
    }

    /**
     * Attempt to create hold using Redis transactions (no Redis TTL)
     */
    private function attemptCreateHold(
        int $productId,
        int $quantity,
        string $holdId,
        string $createdAt,
        string $expiresAt,
        int $expiresAtTimestamp
    ): ?array {
        $redis = Redis::connection();

        // Ensure stock keys are initialized
        $this->initializeStockIfNeeded($productId);

        // Watch all keys involved in the transaction
        $keys = [
            "available_stock:{$productId}",
            "reserved_stock:{$productId}",
            "stock_version:{$productId}",
            "active_holds:{$productId}"
        ];

        $redis->watch($keys);

        try {
            // Read current values within watched context
            $available = (int) $redis->get("available_stock:{$productId}");
            $reserved = (int) $redis->get("reserved_stock:{$productId}");
            $version = (string) $redis->get("stock_version:{$productId}");

            // Check stock availability
            if ($available < $quantity) {
                $redis->unwatch();
                throw new InsufficientStockException($available, $reserved, $version);
            }

            // Calculate new version
            $newVersion = (string) ((int) $version + 1);
            
            // Start transaction
            $redis->multi();

            // Update stock
            $redis->decrby("available_stock:{$productId}", $quantity);
            $redis->incrby("reserved_stock:{$productId}", $quantity);
            $redis->set("stock_version:{$productId}", $newVersion);

            // Create hold record (NO Redis TTL - persistent)
            $redis->hmset("hold:{$holdId}", [
                'product_id' => $productId,
                'qty' => $quantity,
                'created_at' => $createdAt,
                'expires_at' => $expiresAt,
                'expires_at_timestamp' => $expiresAtTimestamp,
                'hold_id' => $holdId,
                'status' => 'active',
                'version' => $newVersion
            ]);

            // Add to product holds set
            $redis->sadd("product_holds:{$productId}", $holdId);
            
            // Update active holds count
            $redis->incrby("active_holds:{$productId}", $quantity);

            // Execute transaction
            $result = $redis->exec();

            if ($result === null) {
                // Transaction failed due to concurrent modification
                return null;
            }

            // Calculate final values
            $finalAvailable = $available - $quantity;
            $finalReserved = $reserved + $quantity;

            return [
                'available' => $finalAvailable,
                'reserved' => $finalReserved,
                'version' => $newVersion
            ];

        } catch (Exception $e) {
            $redis->unwatch();
            throw $e;
        }
    }

    /**
     * Initialize stock if needed (thread-safe)
     */
    private function initializeStockIfNeeded(int $productId): void
    {
        if (Redis::exists("available_stock:{$productId}")) {
            return;
        }

        $lockKey = "stock_init_lock:{$productId}";
        
        // Try to acquire lock
        $lockAcquired = Redis::set($lockKey, 1, 'NX', 'EX', 5);

        if ($lockAcquired) {
            try {
                // Double-check inside lock
                if (!Redis::exists("available_stock:{$productId}")) {
                    $product = Product::find($productId);
                    
                    if ($product) {
                        Redis::set("available_stock:{$productId}", $product->stock);
                        Redis::set("reserved_stock:{$productId}", 0);
                        Redis::set("stock_version:{$productId}", 1);
                        Redis::set("active_holds:{$productId}", 0);

                        Log::info("Stock initialized from database", [
                            'product_id' => $productId,
                            'stock' => $product->stock
                        ]);
                    }
                }
            } finally {
                Redis::del($lockKey);
            }
        } else {
            // Wait for initialization
            $tries = 0;
            while ($tries < 10 && !Redis::exists("available_stock:{$productId}")) {
                usleep(50000); // 50ms
                $tries++;
            }
        }
    }

    /**
     * Validate product exists
     */
    private function validateProduct(int $productId): void
    {
        if (!Product::where('id', $productId)->exists()) {
            throw new ModelNotFoundException("Product not found");
        }
    }

    /**
     * Check Redis availability
     */
    private function checkRedisAvailability(): void
    {
        try {
            Redis::ping();
        } catch (Exception $e) {
            throw new RedisUnavailableException('Redis unavailable');
        }
    }

    /**
     * Format success response
     */
    private function formatSuccessResponse(
        string $holdId,
        string $expiresAt,
        int $productId,
        int $quantity,
        array $result
    ): array {
        return [
            'hold_id' => $holdId,
            'expires_at' => $expiresAt,
            'product_id' => $productId,
            'quantity' => $quantity,
            'available_stock' => $result['available'],
            'reserved_stock' => $result['reserved'],
            'version' => $result['version']
        ];
    }
}