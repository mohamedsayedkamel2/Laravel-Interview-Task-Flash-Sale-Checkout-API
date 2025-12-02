<?php
namespace App\Services\Payment;

use App\Models\IdempotencyKey;
use Illuminate\Support\Facades\Log;

class IdempotencyService
{
    public function processKey(
        string $key, 
        int $orderId, 
        string $status, 
        ?string $currentOrderState = null
    ) {
        $idempotencyRecord = IdempotencyKey::where('key', $key)->lockForUpdate()->first();
        
        if ($idempotencyRecord) {
            $idempotencyRecord->processed = true;
            return $idempotencyRecord;
        }
        
        $dbStatus = $status === 'success' ? 'paid' : 'failed';
        
        $idempotencyRecord = IdempotencyKey::create([
            'key' => $key,
            'order_id' => $orderId,
            'status' => $dbStatus,
            'order_state_at_time' => $currentOrderState,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        $idempotencyRecord->processed = false;
        return $idempotencyRecord;
    }
}