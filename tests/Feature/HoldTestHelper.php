<?php

namespace Tests\Helpers;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

class HoldTestHelper
{
    /**
     * Create an expired hold for testing (with far past expiration)
     */
    public static function createExpiredHold(int $productId, int $quantity = 1): string
    {
        $holdId = 'test-expired-' . Str::random(10);
        
        // Set expiration to far in the past (2 hours ago)
        $expiredAt = Carbon::now()->subHours(2);
        
        $data = [
            'hold_id' => $holdId,
            'product_id' => $productId,
            'qty' => $quantity,
            'status' => 'active',
            'created_at' => Carbon::now()->subHours(3)->toISOString(),
            'expires_at' => $expiredAt->toISOString(),
            'expires_at_timestamp' => $expiredAt->timestamp,
            'updated_at' => Carbon::now()->subHours(3)->toISOString(),
        ];
        
        Redis::hmset("hold:{$holdId}", $data);
        Redis::sadd("product_holds:{$productId}", $holdId);
        
        // Also add to active holds counter
        Redis::incrby("active_holds:{$productId}", $quantity);
        
        // Set Redis stock to track changes
        $currentAvailable = (int) Redis::get("available_stock:{$productId}") ?? 100;
        $currentReserved = (int) Redis::get("reserved_stock:{$productId}") ?? 0;
        
        Redis::set("available_stock:{$productId}", $currentAvailable - $quantity);
        Redis::set("reserved_stock:{$productId}", $currentReserved + $quantity);
        
        Log::debug('Created expired hold for testing', [
            'hold_id' => $holdId,
            'product_id' => $productId,
            'quantity' => $quantity,
            'expires_at_timestamp' => $expiredAt->timestamp,
            'current_time' => time(),
            'is_expired' => time() > $expiredAt->timestamp,
        ]);
        
        return $holdId;
    }
    
    /**
     * Create an active (not expired) hold for testing
     */
    public static function createActiveHold(int $productId, int $quantity = 1): string
    {
        $holdId = 'test-active-' . Str::random(10);
        
        // Set expiration to far in the future (2 hours from now)
        $expiresAt = Carbon::now()->addHours(2);
        
        $data = [
            'hold_id' => $holdId,
            'product_id' => $productId,
            'qty' => $quantity,
            'status' => 'active',
            'created_at' => Carbon::now()->toISOString(),
            'expires_at' => $expiresAt->toISOString(),
            'expires_at_timestamp' => $expiresAt->timestamp,
            'updated_at' => Carbon::now()->toISOString(),
        ];
        
        Redis::hmset("hold:{$holdId}", $data);
        Redis::sadd("product_holds:{$productId}", $holdId);
        Redis::incrby("active_holds:{$productId}", $quantity);
        
        // Update stock
        $currentAvailable = (int) Redis::get("available_stock:{$productId}") ?? 100;
        $currentReserved = (int) Redis::get("reserved_stock:{$productId}") ?? 0;
        
        Redis::set("available_stock:{$productId}", $currentAvailable - $quantity);
        Redis::set("reserved_stock:{$productId}", $currentReserved + $quantity);
        
        return $holdId;
    }
    
    // ... rest of the methods remain the same ...
}