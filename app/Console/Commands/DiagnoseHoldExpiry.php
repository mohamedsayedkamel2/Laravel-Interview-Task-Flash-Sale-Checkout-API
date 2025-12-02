<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class DiagnoseHoldExpiry extends Command
{
    protected $signature = 'diagnose:hold-expiry {holdId?}';
    protected $description = 'Diagnose hold expiration issues';

    public function handle()
    {
        $holdId = $this->argument('holdId') ?: 'test-' . uniqid();
        
        $this->info("=== Hold Expiry Diagnostic ===");
        $this->info("Hold ID: {$holdId}");
        $this->info("Time: " . now()->toISOString());
        $this->info("");
        
        // Check Redis connection
        $this->info("1. Redis Connection:");
        try {
            Redis::ping();
            $this->info("   ✓ Redis connected");
        } catch (\Exception $e) {
            $this->error("   ✗ Redis error: " . $e->getMessage());
        }
        
        // Check if hold exists
        $this->info("2. Hold Existence:");
        $holdKey = "hold:{$holdId}";
        $exists = Redis::exists($holdKey);
        $this->info("   Key: {$holdKey}");
        $this->info("   Exists: " . ($exists ? 'YES' : 'NO'));
        
        if ($exists) {
            $holdData = Redis::hgetall($holdKey);
            $this->info("   Data: " . json_encode($holdData));
            
            // Check expiration
            $expiresAt = (int) ($holdData['expires_at_timestamp'] ?? 0);
            $currentTime = time();
            $isExpired = $expiresAt > 0 && $currentTime > $expiresAt;
            
            $this->info("   Expires At Timestamp: {$expiresAt}");
            $this->info("   Current Timestamp: {$currentTime}");
            $this->info("   Is Expired: " . ($isExpired ? 'YES' : 'NO'));
        }
        
        // Check locks
        $this->info("3. Locks:");
        $lockKey = "expire_lock:{$holdId}";
        $lockExists = Redis::exists($lockKey);
        $this->info("   Lock Key: {$lockKey}");
        $this->info("   Lock Exists: " . ($lockExists ? 'YES' : 'NO'));
        if ($lockExists) {
            $lockValue = Redis::get($lockKey);
            $lockTtl = Redis::ttl($lockKey);
            $this->info("   Lock Value: {$lockValue}");
            $this->info("   Lock TTL: {$lockTtl}s");
        }
        
        // Run the actual command
        $this->info("4. Testing Command Execution:");
        $this->call('holds:process-expired', ['--once' => true]);
        
        return 0;
    }
}