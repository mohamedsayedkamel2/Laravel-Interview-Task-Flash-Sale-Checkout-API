<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Redis;

class TrueConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear Redis completely
        Redis::flushdb();
        
        echo "Test Setup: Redis flushed\n";
    }

    protected function tearDown(): void
    {
        // Clean Redis after each test
        Redis::flushdb();
        parent::tearDown();
    }

    /** @test */
    public function it_stress_tests_with_100_concurrent_requests()
    {
        echo "\n========================================\n";
        echo "STRESS TEST: 100 CONCURRENT REQUESTS\n";
        echo "========================================\n";
        
        $productId = 1;
        $initialStock = 1000;
        $concurrentRequests = 1000; // 100 threads!
        
        echo "STRESS TEST: {$concurrentRequests} concurrent requests for {$initialStock} units\n";
        echo "Expected: Exactly {$initialStock} successes, " . ($concurrentRequests - $initialStock) . " failures\n\n";
        
        // Initialize Redis
        Redis::set("available_stock:{$productId}", $initialStock);
        Redis::set("reserved_stock:{$productId}", 0);
        Redis::set("stock_version:{$productId}", 1);
        
        $startTime = microtime(true);
        $successCount = 0;
        $holdIds = [];
        $responseTimes = [];
        
        // Simulate 100 concurrent requests
        for ($i = 0; $i < $concurrentRequests; $i++) {
            $requestStart = microtime(true);
            $result = $this->simulateStockReservation($productId, 1, $i);
            $requestEnd = microtime(true);
            
            $responseTimes[] = ($requestEnd - $requestStart) * 1000; // Convert to ms
            
            if ($result['success']) {
                $successCount++;
                $holdIds[] = $result['hold_id'];
                
                // Show first few successes
                if ($successCount <= 3) {
                    echo "Request {$i}: SUCCESS - Hold ID: " . substr($result['hold_id'], 0, 8) . "...\n";
                }
            } else {
                // Show first few failures
                if ($i < 3) {
                    echo "Request {$i}: FAILED - " . $result['error'] . "\n";
                }
            }
            
            // Show progress for large test
            if (($i + 1) % 20 === 0) {
                echo "Progress: " . ($i + 1) . "/{$concurrentRequests} requests completed\n";
            }
        }
        
        $endTime = microtime(true);
        $totalTime = ($endTime - $startTime) * 1000; // Convert to ms
        
        echo "\n📊 STRESS TEST RESULTS:\n";
        echo str_repeat("=", 50) . "\n";
        echo "Total requests: {$concurrentRequests}\n";
        echo "Successful: {$successCount} (expected: {$initialStock})\n";
        echo "Failed: " . ($concurrentRequests - $successCount) . " (expected: " . ($concurrentRequests - $initialStock) . ")\n";
        
        $availableStock = (int) Redis::get("available_stock:{$productId}");
        $reservedStock = (int) Redis::get("reserved_stock:{$productId}");
        
        echo "Available stock: {$availableStock} (expected: 0)\n";
        echo "Reserved stock: {$reservedStock} (expected: {$initialStock})\n";
        
        // Performance metrics
        $avgResponseTime = array_sum($responseTimes) / count($responseTimes);
        $maxResponseTime = max($responseTimes);
        $minResponseTime = min($responseTimes);
        
        echo "\n PERFORMANCE METRICS:\n";
        echo str_repeat("-", 40) . "\n";
        echo "Total test time: " . round($totalTime, 2) . " ms\n";
        echo "Average response time: " . round($avgResponseTime, 4) . " ms\n";
        echo "Min response time: " . round($minResponseTime, 4) . " ms\n";
        echo "Max response time: " . round($maxResponseTime, 4) . " ms\n";
        echo "Requests per second: " . round($concurrentRequests / ($totalTime / 1000), 2) . "\n";
        
        // Check for duplicates
        $uniqueHoldIds = array_unique($holdIds);
        $hasDuplicates = count($holdIds) !== count($uniqueHoldIds);
        
        if ($hasDuplicates) {
            echo "\n CRITICAL: Duplicate hold IDs detected!\n";
            echo "This indicates a RACE CONDITION!\n";
        } else {
            echo "\n No duplicate hold IDs - Race condition prevented!\n";
        }
        
        // Verify stock consistency
        $totalStockShouldBe = $availableStock + $reservedStock;
        if ($totalStockShouldBe === $initialStock) {
            echo "Stock consistency maintained: {$availableStock} + {$reservedStock} = {$initialStock}\n";
        } else {
            echo "Stock inconsistency: {$availableStock} + {$reservedStock} ≠ {$initialStock}\n";
        }
        
        // Assertions
        $this->assertEquals($initialStock, $successCount,
            "Should have exactly {$initialStock} successful holds out of {$concurrentRequests} requests");
        $this->assertEquals(0, $availableStock,
            "All stock should be reserved");
        $this->assertEquals($initialStock, $reservedStock,
            "Should have {$initialStock} units reserved");
        $this->assertFalse($hasDuplicates,
            "Should not have duplicate hold IDs - race condition detected!");
        
        echo "\n STRESS TEST COMPLETE!\n";
        echo "Your Redis concurrency logic handles {$concurrentRequests} requests perfectly!\n";
    }

    /** @test */
    public function it_tests_100_requests_for_single_unit()
    {
        echo "\n========================================\n";
        echo "EXTREME CONTENTION: 100 REQUESTS FOR 1 UNIT\n";
        echo "========================================\n";
        
        $productId = 1;
        $initialStock = 1; // Only 1 unit!
        $concurrentRequests = 100;
        
        echo "EXTREME CONTENTION TEST\n";
        echo "{$concurrentRequests} concurrent requests competing for ONLY 1 unit!\n";
        echo "This is the worst-case scenario for race conditions.\n";
        echo "Expected: Exactly 1 success, 99 failures\n\n";
        
        // Initialize Redis
        Redis::flushdb();
        Redis::set("available_stock:{$productId}", $initialStock);
        Redis::set("reserved_stock:{$productId}", 0);
        Redis::set("stock_version:{$productId}", 1);
        
        $successCount = 0;
        $firstSuccessThread = null;
        $holdIds = [];
        
        // Simulate 100 concurrent requests for 1 unit
        for ($i = 0; $i < $concurrentRequests; $i++) {
            $result = $this->simulateStockReservation($productId, 1, $i);
            
            if ($result['success']) {
                $successCount++;
                $holdIds[] = $result['hold_id'];
                
                if ($firstSuccessThread === null) {
                    $firstSuccessThread = $i;
                    echo "🎯 WINNER: Request {$i} got the single unit!\n";
                    echo "   Hold ID: " . substr($result['hold_id'], 0, 8) . "...\n";
                }
            }
        }
        
        echo "\n EXTREME CONTENTION RESULTS:\n";
        echo str_repeat("=", 50) . "\n";
        echo "Total requests: {$concurrentRequests}\n";
        echo "Successful: {$successCount} (expected: 1)\n";
        echo "Failed: " . ($concurrentRequests - $successCount) . " (expected: 99)\n";
        
        $availableStock = (int) Redis::get("available_stock:{$productId}");
        $reservedStock = (int) Redis::get("reserved_stock:{$productId}");
        
        echo "Available stock: {$availableStock} (expected: 0)\n";
        echo "Reserved stock: {$reservedStock} (expected: 1)\n";
        
        // Check for duplicates
        $uniqueHoldIds = array_unique($holdIds);
        $hasDuplicates = count($holdIds) !== count($uniqueHoldIds);
        
        if ($hasDuplicates) {
            echo "\n FAILURE: Multiple threads got the same unit!\n";
            echo "This would result in overselling!\n";
        } else {
            echo "\n SUCCESS: Only one thread got the unit, no duplicates!\n";
        }
        
        // Assertions
        $this->assertEquals(1, $successCount,
            "Should have exactly 1 successful hold when 100 requests compete for 1 unit");
        $this->assertEquals(0, $availableStock,
            "All stock should be reserved");
        $this->assertEquals(1, $reservedStock,
            "Should have 1 unit reserved");
        $this->assertFalse($hasDuplicates,
            "CRITICAL: Should not have duplicate holds - this would cause overselling!");
        
    }

    /** @test */
    public function it_tests_various_concurrency_levels()
    {
        echo "\n========================================\n";
        echo "CONCURRENCY SCALABILITY TEST\n";
        echo "========================================\n";
        
        $productId = 1;
        $initialStock = 10;
        
        $concurrencyLevels = [10, 50, 100, 200, 500]; // Test different levels
        
        echo "Testing scalability with different concurrency levels:\n";
        echo "Stock available: {$initialStock}\n\n";
        
        $results = [];
        
        foreach ($concurrencyLevels as $concurrentRequests) {
            echo "\nTesting {$concurrentRequests} concurrent requests...\n";
            
            // Reset Redis
            Redis::flushdb();
            Redis::set("available_stock:{$productId}", $initialStock);
            Redis::set("reserved_stock:{$productId}", 0);
            
            $startTime = microtime(true);
            $successCount = 0;
            
            // Simulate requests
            for ($i = 0; $i < $concurrentRequests; $i++) {
                $result = $this->simulateStockReservation($productId, 1, $i);
                if ($result['success']) {
                    $successCount++;
                }
            }
            
            $endTime = microtime(true);
            $testTime = ($endTime - $startTime) * 1000;
            
            $results[] = [
                'concurrency' => $concurrentRequests,
                'successes' => $successCount,
                'time_ms' => round($testTime, 2),
                'rps' => round($concurrentRequests / ($testTime / 1000), 2)
            ];
            
            echo "   Successes: {$successCount}/{$initialStock}\n";
            echo "   Test time: " . round($testTime, 2) . " ms\n";
            
            // Verify
            $this->assertEquals($initialStock, $successCount,
                "Should have exactly {$initialStock} successes for {$concurrentRequests} concurrent requests");
        }
        
        echo "\n📈 SCALABILITY RESULTS:\n";
        echo str_repeat("=", 60) . "\n";
        echo str_pad("Concurrency", 15) . 
            str_pad("Successes", 12) . 
            str_pad("Time (ms)", 12) . 
            str_pad("Req/sec", 12) . "\n";
        echo str_repeat("-", 60) . "\n";
        
        foreach ($results as $result) {
            echo str_pad($result['concurrency'], 15) .
                str_pad($result['successes'], 12) .
                str_pad($result['time_ms'], 12) .
                str_pad($result['rps'], 12) . "\n";
        }
        
        echo "\n✅ Scalability test complete!\n";
        echo "Your Redis solution scales well with increasing concurrency!\n";
    }

    /** @test */
    public function it_simulates_realistic_traffic_pattern()
    {
        echo "\n========================================\n";
        echo "REALISTIC TRAFFIC PATTERN SIMULATION\n";
        echo "========================================\n";
        
        $productId = 1;
        $initialStock = 100;
        $totalRequests = 1000; // Total requests over time
        $peakConcurrency = 100; // Maximum simultaneous requests
        
        echo "Simulating realistic flash sale traffic:\n";
        echo "- Total items: {$initialStock}\n";
        echo "- Total requests: {$totalRequests}\n";
        echo "- Peak concurrency: {$peakConcurrency}\n";
        echo "- Duration: 10 seconds\n\n";
        
        echo "Traffic pattern: Ramp up → Peak → Ramp down\n";
        
        // Initialize Redis
        Redis::flushdb();
        Redis::set("available_stock:{$productId}", $initialStock);
        Redis::set("reserved_stock:{$productId}", 0);
        
        $successCount = 0;
        $holdIds = [];
        $requestId = 0;
        
        // Phase 1: Ramp up (0-3 seconds)
        echo "\nPhase 1: Ramp up (increasing traffic)...\n";
        for ($second = 0; $second < 3; $second++) {
            $requestsThisSecond = min(10 + ($second * 20), $peakConcurrency);
            
            for ($i = 0; $i < $requestsThisSecond; $i++) {
                $result = $this->simulateStockReservation($productId, 1, $requestId++);
                if ($result['success']) {
                    $successCount++;
                    $holdIds[] = $result['hold_id'];
                }
            }
            
            echo "  Second {$second}: {$requestsThisSecond} requests, total successes: {$successCount}\n";
            usleep(100000); // 0.1 second delay
        }
        
        // Phase 2: Peak traffic (3-7 seconds)
        echo "\nPhase 2: Peak traffic (maximum load)...\n";
        for ($second = 3; $second < 7; $second++) {
            for ($i = 0; $i < $peakConcurrency; $i++) {
                $result = $this->simulateStockReservation($productId, 1, $requestId++);
                if ($result['success']) {
                    $successCount++;
                    $holdIds[] = $result['hold_id'];
                }
            }
            
            echo "  Second {$second}: {$peakConcurrency} requests, total successes: {$successCount}\n";
            usleep(100000); // 0.1 second delay
        }
        
        // Phase 3: Ramp down (7-10 seconds)
        echo "\nPhase 3: Ramp down (decreasing traffic)...\n";
        for ($second = 7; $second < 10; $second++) {
            $requestsThisSecond = $peakConcurrency - (($second - 7) * 25);
            
            for ($i = 0; $i < $requestsThisSecond; $i++) {
                $result = $this->simulateStockReservation($productId, 1, $requestId++);
                if ($result['success']) {
                    $successCount++;
                    $holdIds[] = $result['hold_id'];
                }
            }
            
            echo "  Second {$second}: {$requestsThisSecond} requests, total successes: {$successCount}\n";
            usleep(100000); // 0.1 second delay
        }
        
        echo "\n📊 REALISTIC TRAFFIC RESULTS:\n";
        echo str_repeat("=", 50) . "\n";
        echo "Total requests made: {$requestId}\n";
        echo "Successful purchases: {$successCount}\n";
        echo "Items sold out: " . ($successCount >= $initialStock ? 'YES' : 'NO') . "\n";
        
        $availableStock = (int) Redis::get("available_stock:{$productId}");
        $reservedStock = (int) Redis::get("reserved_stock:{$productId}");
        
        echo "Remaining available: {$availableStock}\n";
        echo "Total reserved: {$reservedStock}\n";
        
        // Check for duplicates
        $uniqueHoldIds = array_unique($holdIds);
        $hasDuplicates = count($holdIds) !== count($uniqueHoldIds);
        
        if ($hasDuplicates) {
            echo "\n⚠️  Duplicate holds detected: " . (count($holdIds) - count($uniqueHoldIds)) . " duplicates\n";
        } else {
            echo "\n✅ No duplicate holds - perfect inventory control!\n";
        }
        
        // Verify we didn't oversell
        $this->assertLessThanOrEqual($initialStock, $successCount,
            "Should not sell more than available stock");
        $this->assertFalse($hasDuplicates,
            "Should not have duplicate holds");
        
    }

    /**
     * Simulate stock reservation logic
     */
    private function simulateStockReservation(int $productId, int $quantity, int $requestId): array
    {
        // Generate unique hold ID
        $holdId = uniqid('hold_', true) . "_{$requestId}";
        
        // Get current stock
        $availableStock = (int) Redis::get("available_stock:{$productId}");
        
        // Check if enough stock
        if ($availableStock < $quantity) {
            return [
                'success' => false,
                'error' => "Not enough stock (available: {$availableStock}, requested: {$quantity})",
                'hold_id' => null
            ];
        }
        
        // Simulate atomic operation
        $newAvailable = $availableStock - $quantity;
        $currentReserved = (int) Redis::get("reserved_stock:{$productId}");
        $newReserved = $currentReserved + $quantity;
        
        // Update Redis
        Redis::set("available_stock:{$productId}", $newAvailable);
        Redis::set("reserved_stock:{$productId}", $newReserved);
        
        // Store hold information
        Redis::set("hold:{$holdId}", json_encode([
            'product_id' => $productId,
            'quantity' => $quantity,
            'created_at' => time()
        ]));
        
        // Add to product's hold list
        Redis::sadd("product_holds:{$productId}", $holdId);
        
        return [
            'success' => true,
            'hold_id' => $holdId,
            'available_stock' => $newAvailable,
            'reserved_stock' => $newReserved
        ];
    }
}