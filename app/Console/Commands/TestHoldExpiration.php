<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TestHoldExpiration extends Command
{
    protected $signature = 'test:hold-expiry
                           {product_id : Product ID}
                           {quantity=1 : Quantity to hold}
                           {--expired : Create an expired hold}
                           {--minutes=2 : Minutes until expiration (or before if expired)}
                           {--run-command : Run the expiration command after creating hold}';
    
    protected $description = 'Create test holds and test expiration processing';
    
    public function handle()
    {
        $productId = $this->argument('product_id');
        $quantity = $this->argument('quantity');
        
        // Generate unique hold ID
        $holdId = 'test-' . uniqid() . '-' . time();
        
        $expirationMinutes = (int) $this->option('minutes');
        $isExpired = $this->option('expired');
        
        // Calculate expiration time
        $expiresAt = $isExpired 
            ? Carbon::now()->subMinutes($expirationMinutes)
            : Carbon::now()->addMinutes($expirationMinutes);
        
        $this->info("Creating test hold:");
        $this->info("  Hold ID: {$holdId}");
        $this->info("  Product ID: {$productId}");
        $this->info("  Quantity: {$quantity}");
        $this->info("  Status: " . ($isExpired ? 'EXPIRED' : 'ACTIVE'));
        $this->info("  Expires at: " . $expiresAt->format('Y-m-d H:i:s'));
        $this->info("  Current time: " . Carbon::now()->format('Y-m-d H:i:s'));
        
        // Create hold in Redis
        Redis::hmset("hold:{$holdId}", [
            'product_id' => $productId,
            'qty' => $quantity,
            'status' => 'active',
            'expires_at_timestamp' => $expiresAt->timestamp,
            'created_at' => Carbon::now()->toISOString(),
            'user_id' => 999,
            'session_id' => 'test-' . time()
        ]);
        
        // Add to product holds set
        Redis::sadd("product_holds:{$productId}", $holdId);
        
        // Initialize stock if needed
        if (!Redis::exists("available_stock:{$productId}")) {
            Redis::set("available_stock:{$productId}", 100);
            Redis::set("reserved_stock:{$productId}", 0);
            Redis::set("stock_version:{$productId}", 1);
            Redis::set("active_holds:{$productId}", 0);
        }
        
        // Update reserved stock
        $currentReserved = (int) Redis::get("reserved_stock:{$productId}");
        Redis::set("reserved_stock:{$productId}", $currentReserved + $quantity);
        Redis::incrby("active_holds:{$productId}", $quantity);
        
        $this->info("\nHold created successfully!");
        $this->info("Current stock status:");
        $this->info("  Available: " . Redis::get("available_stock:{$productId}"));
        $this->info("  Reserved: " . Redis::get("reserved_stock:{$productId}"));
        $this->info("  Active holds count: " . Redis::get("active_holds:{$productId}"));
        
        // Check if hold would be considered expired
        $currentTime = time();
        $holdData = Redis::hgetall("hold:{$holdId}");
        $expiresAtTimestamp = (int) ($holdData['expires_at_timestamp'] ?? 0);
        
        $isActuallyExpired = $expiresAtTimestamp > 0 && $currentTime > $expiresAtTimestamp;
        
        $this->info("\nExpiration check:");
        $this->info("  Current timestamp: {$currentTime}");
        $this->info("  Hold expires at: {$expiresAtTimestamp}");
        $this->info("  Is expired? " . ($isActuallyExpired ? 'YES' : 'NO'));
        
        if ($this->option('run-command')) {
            $this->info("\nRunning expiration command...");
            
            // Run the expiration command
            $this->call('holds:process-expired', [
                '--once' => true,
                '--batch-size' => 10
            ]);
            
            // Check result
            $updatedHold = Redis::hgetall("hold:{$holdId}");
            $newStatus = $updatedHold['status'] ?? 'NOT FOUND';
            
            $this->info("\nAfter expiration command:");
            $this->info("  Hold status: {$newStatus}");
            $this->info("  Available stock: " . Redis::get("available_stock:{$productId}"));
            $this->info("  Reserved stock: " . Redis::get("reserved_stock:{$productId}"));
            $this->info("  Active holds count: " . Redis::get("active_holds:{$productId}"));
            
            if ($isActuallyExpired && $newStatus === 'expired') {
                $this->info("\n SUCCESS: Hold was correctly expired!");
            } elseif (!$isActuallyExpired && $newStatus === 'active') {
                $this->info("\n SUCCESS: Active hold was not expired!");
            } else {
                $this->error("\n❌ FAILURE: Unexpected result!");
                $this->error("  Expected status: " . ($isActuallyExpired ? 'expired' : 'active'));
                $this->error("  Actual status: {$newStatus}");
            }
        }
        
        return 0;
    }
}