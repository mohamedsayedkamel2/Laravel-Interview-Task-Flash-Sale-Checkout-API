<?php

namespace App\Services\Payment;

use App\Services\Payment\PaymentProcessor;
use App\Services\Payment\IdempotencyService;
use App\Services\Order\OrderRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class PaymentWebhookService
{
    private const MAX_DEADLOCK_RETRIES = 3;
    private const DEADLOCK_RETRY_DELAY = 100; // In millsecs
    
    private PaymentProcessor $paymentProcessor;
    private IdempotencyService $idempotencyService;
    private OrderRepository $orderRepository;
    
    public function __construct(
        PaymentProcessor $paymentProcessor,
        IdempotencyService $idempotencyService,
        OrderRepository $orderRepository
    ) {
        $this->paymentProcessor = $paymentProcessor;
        $this->idempotencyService = $idempotencyService;
        $this->orderRepository = $orderRepository;
    }
    
    public function processWebhook(array $data): array
    {
        $retryCount = 0;
        
        while ($retryCount < self::MAX_DEADLOCK_RETRIES) {
            try {
                return $this->processWebhookTransaction($data);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($this->isDeadlock($e)) {
                    $retryCount++;
                    Log::warning("Deadlock detected, retrying", [
                        'retry_count' => $retryCount,
                        'order_id' => $data['order_id'],
                        'error' => $e->getMessage()
                    ]);
                    
                    if ($retryCount < self::MAX_DEADLOCK_RETRIES) {
                        usleep(self::DEADLOCK_RETRY_DELAY * 1000);
                        continue;
                    }
                }
                throw $e;
            }
        }
        
        throw new \Exception("Max deadlock retries exceeded");
    }
    
    private function processWebhookTransaction(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $order = $this->orderRepository->findWithLock($data['order_id']);
            
            if (!$order) {
                return $this->handleOrderNotFound($data);
            }
            
            if ($this->isOrderFinalized($order)) {
                return $this->handleFinalizedOrder($data, $order);
            }
            
            $idempotencyRecord = $this->idempotencyService->processKey(
                $data['idempotency_key'],
                $data['order_id'],
                $data['status'],
                $order->state
            );
            
            if ($idempotencyRecord->processed) {
                return $this->handleDuplicateWebhook($order, $idempotencyRecord);
            }
            
            return $data['status'] === 'success'
                ? $this->paymentProcessor->processSuccess($order, $idempotencyRecord)
                : $this->paymentProcessor->processFailure($order, $idempotencyRecord);
        });
    }
    
    public function healthCheck(): array
    {
        try {
            $redisOk = $this->testRedisConnection();
            $dbOk = $this->testDatabaseConnection();
            
            return [
                'status' => 'healthy',
                'redis_transactions' => $redisOk ? 'supported' : 'unsupported',
                'database' => $dbOk ? 'connected' : 'failed',
                'timestamp' => now()->toISOString()
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'timestamp' => now()->toISOString()
            ];
        }
    }
    
    public function testRedisTransactions(): array
    {
        try {
            $testKey = 'test_transaction:' . uniqid();
            $testValue = 'test_value_' . time();
            
            Redis::set($testKey, 'initial');
            
            Redis::watch($testKey);
            
            $transaction = Redis::multi();
            $transaction->set($testKey, $testValue);
            $transaction->get($testKey);
            $transaction->expire($testKey, 10);
            
            $results = $transaction->exec();
            
            $finalValue = Redis::get($testKey);
            $ttl = Redis::ttl($testKey);
            
            Redis::del($testKey);
            
            $success = $results !== null && 
                      isset($results[0]) && $results[0] === true &&
                      isset($results[1]) && $results[1] === $testValue &&
                      $finalValue === $testValue &&
                      $ttl > 0;
            
            return [
                'test_success' => $success,
                'transaction_results' => $results,
                'final_value' => $finalValue,
                'ttl' => $ttl,
                'message' => $success ? 'Redis transactions working correctly' : 'Redis transactions failed'
            ];
        } catch (\Exception $e) {
            throw $e;
        }
    }
    
    private function isDeadlock(\Exception $e): bool
    {
        return str_contains($e->getMessage(), 'deadlock') || 
               str_contains($e->getMessage(), 'Deadlock') ||
               in_array($e->getCode(), [40001, 1213]); // MySQL error codes
    }
    
    private function handleOrderNotFound(array $data): array
    {
        Log::warning("Order not found for webhook", $data);
        
        return [
            'status' => 404,
            'data' => [
                'error' => 'Order not found',
                'message' => 'The order does not exist',
                'order_id' => $data['order_id']
            ]
        ];
    }
    
    private function isOrderFinalized($order): bool
    {
        return in_array($order->state, ['paid', 'cancelled']);
    }
    
    private function handleFinalizedOrder(array $data, $order): array
    {
        $idempotencyRecord = $this->idempotencyService->processKey(
            $data['idempotency_key'],
            $data['order_id'],
            $data['status'],
            $order->state
        );
        
        Log::warning("Order already finalized", [
            'order_id' => $order->id,
            'current_state' => $order->state,
            'webhook_status' => $data['status']
        ]);
        
        return [
            'status' => 200,
            'data' => [
                'message' => 'Order already finalized — no changes applied',
                'order_id' => $order->id,
                'state' => $order->state,
                'processed_at' => $idempotencyRecord->created_at ?? null,
            ]
        ];
    }
    
    private function handleDuplicateWebhook($order, $idempotencyRecord): array
    {
        return [
            'status' => 200,
            'data' => [
                'message' => 'Duplicate webhook detected — returning existing state',
                'order_id' => $order->id,
                'state' => $order->state,
                'processed_at' => $idempotencyRecord->created_at,
            ]
        ];
    }
    
    private function testRedisConnection(): bool
    {
        $testKey = 'health_check:' . uniqid();
        
        Redis::set($testKey, 'initial');
        Redis::watch($testKey);
        
        $transaction = Redis::multi();
        $transaction->set($testKey, 'updated');
        $transaction->get($testKey);
        $results = $transaction->exec();
        
        $redisTransactionsOk = $results !== null && isset($results[0]) && $results[0] === true;
        
        Redis::del($testKey);
        
        return $redisTransactionsOk;
    }
    
    private function testDatabaseConnection(): bool
    {
        try {
            DB::transaction(function () {
                DB::table('orders')->count();
            });
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}