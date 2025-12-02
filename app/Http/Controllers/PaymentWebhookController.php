<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\Payment\PaymentWebhookService;
use App\Services\Payment\IdempotencyService;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    private PaymentWebhookService $webhookService;
    private IdempotencyService $idempotencyService;
    
    public function __construct(
        PaymentWebhookService $webhookService,
        IdempotencyService $idempotencyService
    ) {
        $this->webhookService = $webhookService;
        $this->idempotencyService = $idempotencyService;
    }
    
    public function handle(Request $request)
    {
        $validated = $request->validate([
            'idempotency_key' => 'required|string|max:255',
            'order_id'        => 'required|integer',
            'status'          => 'required|string|in:success,failure',
        ]);
        
        Log::info("Payment webhook received", $validated);
        
        try {
            $result = $this->webhookService->processWebhook($validated);
            return response()->json($result['data'], $result['status']);
            
        } catch (\Throwable $e) {
            Log::error("Webhook processing failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $validated
            ]);
            
            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    public function healthCheck()
    {
        return $this->webhookService->healthCheck();
    }
    
    public function testRedisTransactions()
    {
        return $this->webhookService->testRedisTransactions();
    }
}