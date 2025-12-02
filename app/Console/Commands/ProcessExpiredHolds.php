<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Services\Holds\HoldManagementService;
use App\Services\Holds\HoldRepository;

class ProcessExpiredHolds extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'holds:process-expired
                            {--batch-size=100 : Number of holds to process per batch}
                            {--max-execution-time=55 : Maximum execution time in seconds}
                            {--once : Run once and exit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process expired holds and release reserved stock';

    /**
     * Execute the console command.
     */
    public function handle(
        HoldManagementService $holdManagementService,
        HoldRepository $holdRepository
    ): int {
        $this->info('Starting expired holds processing...');
        
        $startTime = microtime(true);
        $maxExecutionTime = (int) $this->option('max-execution-time');
        $batchSize = (int) $this->option('batch-size');
        $runOnce = $this->option('once');
        
        $processedCount = 0;
        $expiredCount = 0;
        $errors = [];
        
        try {
            do {
                // Use the repository's optimized method to find expired holds
                $expiredHolds = $holdRepository->findExpiredHolds($batchSize);
                
                if (empty($expiredHolds)) {
                    $this->info('No expired holds found.');
                    break;
                }
                
                $this->info(sprintf('Processing %d expired holds...', count($expiredHolds)));
                
                foreach ($expiredHolds as $hold) {
                    if ($this->shouldStop($startTime, $maxExecutionTime)) {
                        $this->warn('Maximum execution time reached.');
                        break 2;
                    }
                    
                    $processedCount++;
                    $holdId = $hold['hold_id'] ?? 'unknown';
                    
                    try {
                        // Use the new locking mechanism that works with broken Redis NX
                        if ($this->processExpiredHoldWithSimpleLock($hold, $holdManagementService, $holdRepository)) {
                            $expiredCount++;
                            $this->info(sprintf('Hold %s expired successfully.', $holdId));
                        }
                    } catch (\Exception $e) {
                        $errors[] = [
                            'hold_id' => $holdId,
                            'error' => $e->getMessage()
                        ];
                        
                        Log::error('Failed to process expired hold', [
                            'hold_id' => $holdId,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                        
                        // Don't show error for every failed hold to avoid spam
                        if (count($errors) <= 5) {
                            $this->error(sprintf('Failed to process hold %s: %s', $holdId, $e->getMessage()));
                        }
                    }
                }
                
                $this->info(sprintf('Batch processed: %d processed, %d expired.', $processedCount, $expiredCount));
                
            } while (!$runOnce && !$this->shouldStop($startTime, $maxExecutionTime));
            
            $executionTime = round(microtime(true) - $startTime, 2);
            
            $this->info(sprintf('Processing completed. Total: %d processed, %d expired in %s seconds.', 
                $processedCount, $expiredCount, $executionTime));
            
            if (!empty($errors)) {
                $this->warn(sprintf('Encountered %d errors during processing.', count($errors)));
            }
            
            Log::info('Expired holds processing completed', [
                'processed_count' => $processedCount,
                'expired_count' => $expiredCount,
                'error_count' => count($errors),
                'execution_time' => $executionTime
            ]);
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            Log::error('Critical error in expired holds processing', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->error(sprintf('Critical error: %s', $e->getMessage()));
            return self::FAILURE;
        }
    }
    
    /**
     * Process expired hold with SIMPLE locking (works with broken Redis NX)
     */
private function processExpiredHoldWithSimpleLock(array $hold, HoldManagementService $service, HoldRepository $repository): bool
{
    $holdId = $hold['hold_id'];
    
    try {
        $lockKey = "expire_lock:{$holdId}";
        $processId = gethostname() . '-' . getmypid() . '-' . microtime(true);
        
        // MANUAL NX CHECK
        if (Redis::exists($lockKey)) {
            Log::debug('Lock already exists, skipping hold', [
                'hold_id' => $holdId,
                'lock_key' => $lockKey
            ]);
            return false;
        }
        
        // Set lock
        Redis::setex($lockKey, 5, $processId);
        
        // Double-check we got the lock
        if (Redis::get($lockKey) !== $processId) {
            Log::debug('Race condition: someone else got the lock', [
                'hold_id' => $holdId,
                'our_pid' => $processId
            ]);
            return false;
        }
        
        try {
            // Get current hold data
            $currentHold = $repository->getHold($holdId);
            
            if (!$currentHold) {
                Log::debug('Hold not found', ['hold_id' => $holdId]);
                return false;
            }
            
            // Validate hold is still active
            if (($currentHold['status'] ?? '') !== 'active') {
                Log::debug('Hold not active', [
                    'hold_id' => $holdId,
                    'status' => $currentHold['status'] ?? 'unknown'
                ]);
                return false;
            }
            
            // Validate expiration
            $expiresAt = (int) ($currentHold['expires_at_timestamp'] ?? 0);
            $currentTime = time();
            
            if ($expiresAt === 0) {
                Log::warning('Hold has no expiration timestamp', ['hold_id' => $holdId]);
                return false;
            }
            
            if ($currentTime < $expiresAt) {
                Log::debug('Hold not yet expired', [
                    'hold_id' => $holdId,
                    'expires_at' => $expiresAt,
                    'current_time' => $currentTime
                ]);
                return false;
            }
            
            // Use the centralized expiration logic
            Log::info('Attempting to expire hold via HoldManagementService', [
                'hold_id' => $holdId,
                'product_id' => $currentHold['product_id'] ?? null,
                'quantity' => $currentHold['qty'] ?? 0
            ]);
            
            $service->expireHold($holdId);
            
            Log::debug('Hold expired successfully via HoldManagementService', ['hold_id' => $holdId]);
            return true;
            
        } finally {
            // Clean up lock
            if (Redis::get($lockKey) === $processId) {
                Redis::del($lockKey);
            }
        }
        
    } catch (\Exception $e) {
        Log::error('Error processing expired hold', [
            'hold_id' => $holdId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return false;
    }
}
    
    /**
     * Alternative: Process hold WITHOUT locking (for testing or when locks fail)
     */
    private function processExpiredHoldWithoutLock(array $hold, HoldManagementService $service, HoldRepository $repository): bool
    {
        $holdId = $hold['hold_id'];
        
        try {
            // Double-check hold status and expiration
            $currentHold = $repository->getHold($holdId);
            
            if (!$currentHold) {
                Log::debug('Hold not found, skipping', ['hold_id' => $holdId]);
                return false;
            }
            
            if (($currentHold['status'] ?? '') !== 'active') {
                Log::debug('Hold not active, skipping', [
                    'hold_id' => $holdId,
                    'status' => $currentHold['status'] ?? 'unknown'
                ]);
                return false;
            }
            
            // Check expiration
            $expiresAt = (int) ($currentHold['expires_at_timestamp'] ?? 0);
            $currentTime = time();
            
            if ($expiresAt === 0) {
                Log::warning('Hold has no expiration timestamp', ['hold_id' => $holdId]);
                return false;
            }
            
            if ($currentTime < $expiresAt) {
                Log::debug('Hold not yet expired, skipping', [
                    'hold_id' => $holdId,
                    'expires_at' => $expiresAt,
                    'current_time' => $currentTime
                ]);
                return false;
            }
            
            // Expire the hold
            $service->expireHold($holdId);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Error processing expired hold', [
                'hold_id' => $holdId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Check if processing should stop
     */
    private function shouldStop(float $startTime, int $maxExecutionTime): bool
    {
        return (microtime(true) - $startTime) > $maxExecutionTime;
    }
}