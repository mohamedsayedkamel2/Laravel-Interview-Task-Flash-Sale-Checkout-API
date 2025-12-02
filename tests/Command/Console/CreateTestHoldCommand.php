<?php

namespace Tests\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Carbon\Carbon;

class CreateTestHoldCommand extends Command
{
    protected $signature = 'test:create-hold 
                           {product_id : Product ID}
                           {quantity : Quantity to hold}
                           {--expired : Create an expired hold}
                           {--minutes=5 : Minutes until expiration (or before if expired)}';
    
    protected $description = 'Create a test hold for expiration testing';
    
    public function handle()
    {
        $productId = $this->argument('product_id');
        $quantity = $this->argument('quantity');
        $holdId = 'test-hold-' . time() . '-' . rand(1000, 9999);
        
        $expirationMinutes = (int) $this->option('minutes');
        $isExpired = $this->option('expired');
        
        $expiresAt = $isExpired 
            ? Carbon::now()->subMinutes($expirationMinutes)
            : Carbon::now()->addMinutes($expirationMinutes);
        
        Redis::hmset("hold:{$holdId}", [
            'product_id' => $productId,
            'qty' => $quantity,
            'status' => 'active',
            'expires_at_timestamp' => $expiresAt->timestamp,
            'created_at' => Carbon::now()->toISOString(),
            'user_id' => 999,
            'session_id' => 'test-' . time()
        ]);
        
        Redis::sadd("product_holds:{$productId}", $holdId);
        
        // Also initialize stock if needed
        if (!Redis::exists("available_stock:{$productId}")) {
            Redis::set("available_stock:{$productId}", 10);
            Redis::set("reserved_stock:{$productId}", 0);
        }
        
        // Increment reserved stock
        $currentReserved = (int) Redis::get("reserved_stock:{$productId}");
        Redis::set("reserved_stock:{$productId}", $currentReserved + $quantity);
        
        $this->info("Created hold: {$holdId}");
        $this->info("Product ID: {$productId}, Quantity: {$quantity}");
        $this->info("Status: " . ($isExpired ? 'EXPIRED' : 'ACTIVE'));
        $this->info("Expires at: " . $expiresAt->format('Y-m-d H:i:s'));
        
        return 0;
    }
}