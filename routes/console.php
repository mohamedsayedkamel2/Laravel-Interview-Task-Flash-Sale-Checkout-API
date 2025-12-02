<?php

use Illuminate\Support\Facades\Schedule;

// Schedule hold expiry processing
Schedule::command('holds:process-expired --once')
    ->everyMinute()
    ->name('hold-expiry-processor')
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/hold-expiry.log'));

// Heartbeat to verify scheduler is working
Schedule::call(function () {
    \Log::info('SCHEDULER_HEARTBEAT', [
        'timestamp' => now()->toISOString(),
        'holds_total' => count(\Illuminate\Support\Facades\Redis::keys('hold:*')),
        'available_stock_1' => \Illuminate\Support\Facades\Redis::get('available_stock:1'),
        'reserved_stock_1' => \Illuminate\Support\Facades\Redis::get('reserved_stock:1')
    ]);
})->everyMinute();