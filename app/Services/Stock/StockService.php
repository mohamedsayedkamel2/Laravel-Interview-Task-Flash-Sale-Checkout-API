<?php

namespace App\Services\Stock;

use App\Models\Product;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Exception;

class StockService
{
    private const STOCK_RETRY_ATTEMPTS = 3;
    private const STOCK_RETRY_DELAY_MS = 100;

    public function getStock(int $productId): array
    {
        $product = $this->findProduct($productId);
        $stock = $this->getAtomicStock($product);
        
        return [
            "available_stock" => $stock['available'],
            "reserved_stock" => $stock['reserved'],
            "version" => $stock['version'],
        ];
    }

    private function getAtomicStock(Product $product): array
    {
        try {
            return $this->getRedisStock($product);
        } catch (Exception $e) {
            Log::warning("Redis stock retrieval failed", [
                'product_id' => $product->id,
                'error' => $e->getMessage()
            ]);
            
            return $this->getPessimisticStock($product);
        }
    }

    private function getRedisStock(Product $product): array
    {
        $productId = $product->id;
        $baseStock = $product->stock;
        
        $available = Redis::get("available_stock:{$productId}");
        $reserved = Redis::get("reserved_stock:{$productId}");
        $version = Redis::get("stock_version:{$productId}");
        
        if ($available === null) {
            return $this->initializeAndGetStock($productId, $baseStock);
        }
        
        return [
            'available' => (int) $available,
            'reserved' => (int) $reserved,
            'version' => (string) ($version ?? '1'),
        ];
    }

    private function initializeAndGetStock(int $productId, int $baseStock): array
    {
        $initialized = Redis::setnx("available_stock:{$productId}", $baseStock);
        
        if ($initialized) {
            Redis::set("reserved_stock:{$productId}", 0);
            Redis::set("stock_version:{$productId}", 1);
            
            return [
                'available' => $baseStock,
                'reserved' => 0,
                'version' => '1',
            ];
        } else {
            usleep(10000);
            return [
                'available' => (int) Redis::get("available_stock:{$productId}"),
                'reserved' => (int) Redis::get("reserved_stock:{$productId}"),
                'version' => (string) Redis::get("stock_version:{$productId}"),
            ];
        }
    }

    private function getPessimisticStock(Product $product): array
    {
        return DB::transaction(function () use ($product) {
            $lockedProduct = Product::where('id', $product->id)
                ->lockForUpdate()
                ->first();
                
            $reservedStock = DB::table('holds')
                ->where('product_id', $product->id)
                ->where('status', 'active')
                ->where('expires_at', '>', Carbon::now())
                ->sum('quantity');
                
            $available = max(0, $lockedProduct->stock - $reservedStock);
            
            return [
                'available' => $available,
                'reserved' => $reservedStock,
                'version' => 'db_lock_' . time(),
            ];
        });
    }

    public function reserveStock(int $productId, int $quantity): array
    {
        for ($attempt = 0; $attempt < self::STOCK_RETRY_ATTEMPTS; $attempt++) {
            try {
                $result = $this->attemptRedisReservation($productId, $quantity);
                
                if (isset($result['success'])) {
                    return $result;
                }
            } catch (ConcurrentModificationException $e) {
                if ($attempt < self::STOCK_RETRY_ATTEMPTS - 1) {
                    usleep(self::STOCK_RETRY_DELAY_MS * 1000);
                    continue;
                }
                Log::warning("Max retries reached for stock reservation", ['product_id' => $productId]);
            } catch (Exception $e) {
                Log::error("Stock reservation failed", [
                    'product_id' => $productId,
                    'attempt' => $attempt + 1,
                    'error' => $e->getMessage()
                ]);
            }
            
            break;
        }
        
        return $this->reserveStockPessimistic($productId, $quantity);
    }

    private function attemptRedisReservation(int $productId, int $quantity): array
    {
        $redis = Redis::connection();
        
        $redis->watch([
            "available_stock:{$productId}",
            "reserved_stock:{$productId}",
            "stock_version:{$productId}"
        ]);
        
        try {
            $available = (int) $redis->get("available_stock:{$productId}");
            $reserved = (int) $redis->get("reserved_stock:{$productId}");
            $version = (string) $redis->get("stock_version:{$productId}");
            
            if ($available < $quantity) {
                $redis->unwatch();
                return [
                    'success' => false,
                    'available_stock' => $available,
                    'reserved_stock' => $reserved,
                    'reason' => 'insufficient_stock'
                ];
            }
            
            $redis->multi();
            
            $redis->decrby("available_stock:{$productId}", $quantity);
            $redis->incrby("reserved_stock:{$productId}", $quantity);
            $redis->incr("stock_version:{$productId}");
            
            $result = $redis->exec();
            
            if ($result === null) {
                throw new ConcurrentModificationException("Concurrent modification detected");
            }
            
            $newAvailable = $available - $quantity;
            $newReserved = $reserved + $quantity;
            $newVersion = (string) ((int) $version + 1);
            
            return [
                'success' => true,
                'available_stock' => $newAvailable,
                'reserved_stock' => $newReserved,
                'version' => $newVersion,
            ];
            
        } catch (Exception $e) {
            $redis->unwatch();
            throw $e;
        }
    }

    private function reserveStockPessimistic(int $productId, int $quantity): array
    {
        return DB::transaction(function () use ($productId, $quantity) {
            $product = Product::where('id', $productId)
                ->lockForUpdate()
                ->first();
                
            if (!$product) {
                return ['success' => false, 'reason' => 'product_not_found'];
            }
            
            $reservedStock = DB::table('holds')
                ->where('product_id', $productId)
                ->where('status', 'active')
                ->where('expires_at', '>', Carbon::now())
                ->sum('quantity');
                
            $availableStock = $product->stock - $reservedStock;
            
            if ($availableStock < $quantity) {
                return [
                    'success' => false,
                    'available_stock' => $availableStock,
                    'reason' => 'insufficient_stock'
                ];
            }
            
            try {
                $this->updateRedisStock($productId, $quantity, 0);
            } catch (Exception $e) {
                Log::warning("Redis update failed after DB reservation", [
                    'product_id' => $productId,
                    'error' => $e->getMessage()
                ]);
            }
            
            return [
                'success' => true,
                'available_stock' => $availableStock - $quantity,
                'reserved_stock' => $reservedStock + $quantity,
                'version' => 'db_reserved_' . time(),
            ];
        });
    }

    public function releaseStock(int $productId, int $quantity): array
    {
        for ($attempt = 0; $attempt < self::STOCK_RETRY_ATTEMPTS; $attempt++) {
            try {
                return $this->attemptRedisRelease($productId, $quantity);
            } catch (ConcurrentModificationException $e) {
                if ($attempt < self::STOCK_RETRY_ATTEMPTS - 1) {
                    usleep(self::STOCK_RETRY_DELAY_MS * 1000);
                    continue;
                }
                Log::warning("Max retries reached for stock release", ['product_id' => $productId]);
            } catch (Exception $e) {
                Log::error("Stock release failed", [
                    'product_id' => $productId,
                    'attempt' => $attempt + 1,
                    'error' => $e->getMessage()
                ]);
            }
            
            break;
        }
        
        return $this->releaseStockPessimistic($productId, $quantity);
    }

    private function attemptRedisRelease(int $productId, int $quantity): array
    {
        $redis = Redis::connection();
        
        $redis->watch([
            "available_stock:{$productId}",
            "reserved_stock:{$productId}",
            "stock_version:{$productId}"
        ]);
        
        try {
            $available = (int) $redis->get("available_stock:{$productId}");
            $reserved = (int) $redis->get("reserved_stock:{$productId}");
            $version = (string) $redis->get("stock_version:{$productId}");
            
            if ($reserved < $quantity) {
                $redis->unwatch();
                throw new Exception("Cannot release more stock than reserved. Reserved: {$reserved}, Requested: {$quantity}");
            }
            
            $redis->multi();
            $redis->incrby("available_stock:{$productId}", $quantity);
            $redis->decrby("reserved_stock:{$productId}", $quantity);
            $redis->incr("stock_version:{$productId}");
            
            $result = $redis->exec();
            
            if ($result === null) {
                throw new ConcurrentModificationException("Concurrent modification detected");
            }
            
            $newAvailable = $available + $quantity;
            $newReserved = $reserved - $quantity;
            $newVersion = (string) ((int) $version + 1);
            
            return [
                'available_stock' => $newAvailable,
                'reserved_stock' => $newReserved,
                'version' => $newVersion,
            ];
            
        } catch (Exception $e) {
            $redis->unwatch();
            throw $e;
        }
    }

    private function updateRedisStock(int $productId, int $availableDelta, int $reservedDelta): void
    {
        $redis = Redis::connection();
        
        $redis->watch([
            "available_stock:{$productId}",
            "reserved_stock:{$productId}",
            "stock_version:{$productId}"
        ]);
        
        try {
            $redis->multi();
            
            if ($availableDelta !== 0) {
                $redis->incrby("available_stock:{$productId}", $availableDelta);
            }
            
            if ($reservedDelta !== 0) {
                $redis->incrby("reserved_stock:{$productId}", $reservedDelta);
            }
            
            $redis->incr("stock_version:{$productId}");
            
            $result = $redis->exec();
            
            if ($result === null) {
                usleep(50000);
                Redis::incrby("available_stock:{$productId}", $availableDelta);
                Redis::incrby("reserved_stock:{$productId}", $reservedDelta);
                Redis::incr("stock_version:{$productId}");
            }
            
        } catch (Exception $e) {
            $redis->unwatch();
            throw $e;
        }
    }

    public function refreshCache(int $productId): array
    {
        $product = $this->findProduct($productId);
        
        $redis = Redis::connection();
        
        $redis->watch([
            "available_stock:{$productId}",
            "reserved_stock:{$productId}",
            "stock_version:{$productId}"
        ]);
        
        try {
            $redis->multi();
            $redis->set("available_stock:{$productId}", $product->stock);
            $redis->set("reserved_stock:{$productId}", 0);
            $redis->incr("stock_version:{$productId}");
            
            $result = $redis->exec();
            
            if ($result === null) {
                return $this->refreshCache($productId);
            }
            
            return [
                'message' => 'Stock cache refreshed',
                'product_id' => $productId,
                'available_stock' => $product->stock,
                'reserved_stock' => 0,
                'version' => Redis::get("stock_version:{$productId}"),
            ];
            
        } catch (Exception $e) {
            $redis->unwatch();
            Log::error("Stock cache refresh failed", ['product_id' => $productId]);
            throw $e;
        }
    }

    public function getStockBreakdown(int $productId): array
    {
        $product = $this->findProduct($productId);
        $stock = $this->getAtomicStock($product);
        
        $calculatedAvailable = $product->stock - $stock['reserved'];
        $consistent = $stock['available'] == $calculatedAvailable;
        
        if (!$consistent) {
            Log::warning("Stock inconsistency detected", [
                'product_id' => $productId,
                'redis_available' => $stock['available'],
                'calculated_available' => $calculatedAvailable
            ]);
        }
        
        return [
            'product_id' => $productId,
            'base_stock' => $product->stock,
            'reserved_stock' => $stock['reserved'],
            'available_stock' => $stock['available'],
            'version' => $stock['version'],
            'calculated_available' => $calculatedAvailable,
            'consistent' => $consistent,
            'redis_status' => $this->redisStatus(),
        ];
    }

    public function bulkReserveStock(array $reservations): array
    {
        $results = [];
        $failedProducts = [];
        
        foreach ($reservations as $productId => $quantity) {
            $result = $this->reserveStock($productId, $quantity);
            $results[$productId] = $result;
            
            if (!$result['success']) {
                $failedProducts[$productId] = $quantity;
            }
        }
        
        if (!empty($failedProducts)) {
            foreach ($reservations as $productId => $quantity) {
                if ($results[$productId]['success'] ?? false) {
                    $this->releaseStock($productId, $quantity);
                }
            }
            
            return $this->bulkReservePessimistic($reservations);
        }
        
        return $results;
    }

    private function bulkReservePessimistic(array $reservations): array
    {
        return DB::transaction(function () use ($reservations) {
            $results = [];
            
            foreach ($reservations as $productId => $quantity) {
                $result = $this->reserveStockPessimistic($productId, $quantity);
                $results[$productId] = $result;
                
                if (!$result['success']) {
                    throw new Exception("Bulk reservation failed for product {$productId}");
                }
            }
            
            return $results;
        });
    }

    private function findProduct(int $productId): Product
    {
        $product = Product::find($productId);
        if (!$product) {
            throw new Exception("Product {$productId} not found");
        }
        return $product;
    }

    private function redisStatus(): string
    {
        try {
            Redis::ping();
            return 'connected';
        } catch (Exception $e) {
            return 'disconnected';
        }
    }
}

class ConcurrentModificationException extends Exception
{
    public function __construct($message = "Concurrent modification detected", $code = 0, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}