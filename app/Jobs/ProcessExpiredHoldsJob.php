<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Services\Holds\HoldExpiryService;

class ProcessExpiredHoldsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 50;
    
    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;
    
    /**
     * The number of seconds to wait before retrying the job.
     */
    public $backoff = [60, 120, 300];
    
    /**
     * Execute the job.
     */
    public function handle(HoldExpiryService $expiryService): void
    {
        try {
            Log::info('Starting expired holds job processing...');
            
            if (!$expiryService->shouldProcessExpiredHolds()) {
                Log::debug('Skipping hold expiry processing - too soon since last run');
                return;
            }
            
            $results = $expiryService->processExpiredHolds();
            
            Log::info('Expired holds job completed', $results);
            
            // Update last run time
            $expiryService->updateLastRun();
            
        } catch (\Exception $e) {
            Log::error('Failed to process expired holds job', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Re-throw to allow retry logic
            throw $e;
        }
    }
    
    /**
     * Handle a job failure.
     */
    public function failed(\Exception $exception): void
    {
        Log::error('Expired holds job failed after all attempts', [
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);
    }
}