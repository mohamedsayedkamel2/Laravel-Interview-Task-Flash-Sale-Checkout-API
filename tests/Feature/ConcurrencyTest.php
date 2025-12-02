<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Product;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use GuzzleHttp\Client;
use GuzzleHttp\Promise;

class ConcurrencyTest extends TestCase
{
    private static $productCreated = false;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Only run migrations if needed
        if (!Schema::hasTable('products')) {
            $this->artisan('migrate');
        }
        
        // Clear Redis
        Redis::flushdb();
        
        // Create product only if it doesn't exist
        if (!Product::find(1)) {
            Product::create([
                'id' => 6,
                'name' => 'Flash Sale Product',
                'price' => 2999,
                'stock' => 5,
                'sku' => 'TEST-FLASH'
            ]);
        }
    }

    protected function tearDown(): void
    {
        // Don't flush Redis here - let setUp handle it
        // Keep the product in database for next test
        parent::tearDown();
    }

    /** @test */
    public function it_handles_true_parallel_requests_with_guzzle_async()
    {
        $product = Product::find(1);
        
        if (!$product) {
            $this->fail('Product not found');
        }

        // Initialize Redis stock with correct value
        Redis::set("available_stock:1", 5);
        Redis::set("reserved_stock:1", 0);
        Redis::set("stock_version:1", 1);
        Redis::set("active_holds:1", 0);

        $concurrentRequests = 10;
        $client = new Client([
            'base_uri' => 'http://localhost:8000',
            'timeout' => 10,
            'connect_timeout' => 2
        ]);

        $promises = [];
        
        echo "Starting {$concurrentRequests} concurrent requests...\n";

        // Create all async promises
        for ($i = 0; $i < $concurrentRequests; $i++) {
            $promises[$i] = $client->postAsync('/api/holds', [
                'json' => [
                    'product_id' => 1,
                    'qty' => 1
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                ]
            ]);
        }

        // Wait for ALL promises to complete
        $responses = Promise\Utils::settle($promises)->wait();

        // Process results
        $successfulHolds = 0;
        $holdIds = [];
        
        foreach ($responses as $i => $response) {
            if ($response['state'] === 'fulfilled') {
                $httpResponse = $response['value'];
                $status = $httpResponse->getStatusCode();
                $body = json_decode($httpResponse->getBody(), true);
                
                if ($status === 201) {
                    $successfulHolds++;
                    $holdIds[] = $body['hold_id'] ?? null;
                }
                echo "Request {$i}: Status {$status}\n";
            } else {
                echo "Request {$i}: FAILED - " . $response['reason']->getMessage() . "\n";
            }
        }

        // Check for duplicates
        $uniqueHoldIds = array_unique(array_filter($holdIds));
        $hasDuplicates = count($holdIds) !== count($uniqueHoldIds);

        // Assertions
        $this->assertFalse($hasDuplicates, "Duplicate hold IDs detected - RACE CONDITION!");
        
        // Should only allow 5 successful holds (stock is 5)
        $this->assertEquals(5, $successfulHolds,
            "Should have exactly 5 successful holds for 5 stock units. Got: {$successfulHolds}");

        // Get final stock directly from Redis (more reliable)
        $finalStock = (int) Redis::get("available_stock:1");
        $expectedStock = 0; // All 5 units should be reserved
        
        $this->assertEquals($expectedStock, $finalStock,
            "Final stock should be {$expectedStock}, got {$finalStock}");

        // Also verify reserved stock
        $reservedStock = (int) Redis::get("reserved_stock:1");
        $this->assertEquals(5, $reservedStock,
            "Reserved stock should be 5, got {$reservedStock}");

        echo "\nResults:\n";
        echo "- Successful holds: {$successfulHolds}/{$concurrentRequests} (Expected: 5)\n";
        echo "- Duplicate holds: " . ($hasDuplicates ? 'YES' : 'NO') . "\n";
        echo "- Final available stock: {$finalStock} (Expected: 0)\n";
        echo "- Final reserved stock: {$reservedStock} (Expected: 5)\n";
    }

    /** @test */
    public function it_handles_concurrent_boundary_condition()
    {
        $product = Product::find(1);
        
        if (!$product) {
            $this->fail('Product not found');
        }

        // Initialize Redis
        Redis::set("available_stock:1", 5);
        Redis::set("reserved_stock:1", 0);
        Redis::set("stock_version:1", 1);
        Redis::set("active_holds:1", 0);

        // First, use up 4 units
        for ($i = 0; $i < 4; $i++) {
            $response = $this->postJson('/api/holds', [
                'product_id' => 1,
                'qty' => 1
            ]);
            
            $this->assertEquals(201, $response->status(), 
                "Failed to create initial hold #{$i}");
        }

        // Verify we have 1 unit left
        $availableAfter4 = (int) Redis::get("available_stock:1");
        $this->assertEquals(1, $availableAfter4, 
            "Should have 1 unit available after 4 holds");

        // Now launch 5 concurrent requests for the last unit
        $client = new Client(['base_uri' => 'http://localhost:8000', 'timeout' => 10]);
        $promises = [];
        
        for ($i = 0; $i < 5; $i++) {
            $promises[$i] = $client->postAsync('/api/holds', [
                'json' => ['product_id' => 1, 'qty' => 1],
                'headers' => ['Content-Type' => 'application/json']
            ]);
        }

        $responses = Promise\Utils::settle($promises)->wait();
        
        $successCount = 0;
        foreach ($responses as $response) {
            if ($response['state'] === 'fulfilled' && $response['value']->getStatusCode() === 201) {
                $successCount++;
            }
        }

        // Only ONE should succeed for the last unit
        $this->assertEquals(1, $successCount, 
            "Should have exactly 1 success for the last unit. Got: {$successCount}");

        // Verify final stock is 0
        $finalStock = (int) Redis::get("available_stock:1");
        $this->assertEquals(0, $finalStock, 
            "Final stock should be 0, got {$finalStock}");

        echo "Boundary condition test:\n";
        echo "- Successfully grabbed last unit: {$successCount}/5\n";
        echo "- Final available stock: {$finalStock}\n";
    }

    /** @test */
    public function it_detects_stock_negative_race_condition()
    {
        $product = Product::find(1);
        
        if (!$product) {
            $this->fail('Product not found');
        }

        // Initialize Redis
        Redis::set("available_stock:1", 5);
        Redis::set("reserved_stock:1", 0);
        Redis::set("stock_version:1", 1);
        Redis::set("active_holds:1", 0);

        // Try to oversell: 8 requests for 5 units
        $client = new Client(['base_uri' => 'http://localhost:8000', 'timeout' => 10]);
        $promises = [];
        
        for ($i = 0; $i < 8; $i++) {
            $promises[$i] = $client->postAsync('/api/holds', [
                'json' => ['product_id' => 1, 'qty' => 1],
                'headers' => ['Content-Type' => 'application/json']
            ]);
        }

        $responses = Promise\Utils::settle($promises)->wait();
        
        $successCount = 0;
        foreach ($responses as $response) {
            if ($response['state'] === 'fulfilled' && $response['value']->getStatusCode() === 201) {
                $successCount++;
            }
        }

        // Critical: Stock should NEVER go negative
        $finalStock = (int) Redis::get("available_stock:1");
        $this->assertGreaterThanOrEqual(0, $finalStock, 
            "STOCK WENT NEGATIVE! Final stock: {$finalStock}");
        
        // Should only allow 5 successful holds
        $this->assertEquals(5, $successCount,
            "Should create exactly 5 holds for 5 units. Got: {$successCount}");
        
        echo "Race condition test:\n";
        echo "- Successful holds: {$successCount}/8 (Expected: 5)\n";
        echo "- Final stock: {$finalStock} (Should be >= 0)\n";
    }

    /** @test */
    public function it_tests_massive_concurrency_stress_test()
    {
        $product = Product::find(1);
        
        if (!$product) {
            $this->fail('Product not found');
        }

        // Initialize Redis
        Redis::set("available_stock:1", 5);
        Redis::set("reserved_stock:1", 0);
        Redis::set("stock_version:1", 1);
        Redis::set("active_holds:1", 0);

        // Stress test: 50 concurrent requests for 5 units
        $client = new Client(['base_uri' => 'http://localhost:8000', 'timeout' => 30]);
        $promises = [];
        
        for ($i = 0; $i < 50; $i++) {
            $promises[$i] = $client->postAsync('/api/holds', [
                'json' => ['product_id' => 1, 'qty' => 1],
                'headers' => ['Content-Type' => 'application/json']
            ]);
        }

        $startTime = microtime(true);
        $responses = Promise\Utils::settle($promises)->wait();
        $endTime = microtime(true);
        
        $successCount = 0;
        foreach ($responses as $response) {
            if ($response['state'] === 'fulfilled' && $response['value']->getStatusCode() === 201) {
                $successCount++;
            }
        }

        $duration = $endTime - $startTime;
        
        // Should only allow 5 successful holds
        $this->assertEquals(5, $successCount,
            "Should create exactly 5 holds for 5 units under 50 concurrent requests. Got: {$successCount}");
        
        // Verify stock is 0
        $finalStock = (int) Redis::get("available_stock:1");
        $this->assertEquals(0, $finalStock,
            "Final stock should be 0, got {$finalStock}");
        
        echo "Stress test:\n";
        echo "- Successful holds: {$successCount}/50 (Expected: 5)\n";
        echo "- Duration: {$duration}s\n";
        echo "- Final stock: {$finalStock} (Expected: 0)\n";
    }

    /** @test */
    public function it_tests_concurrent_validation()
    {
        $product = Product::find(1);
        
        if (!$product) {
            $this->fail('Product not found');
        }

        // Initialize Redis
        Redis::set("available_stock:1", 5);
        Redis::set("reserved_stock:1", 0);
        Redis::set("stock_version:1", 1);
        Redis::set("active_holds:1", 0);

        // Test that validation happens BEFORE stock reservation
        $successCount = 0;
        $errorMessages = [];
        
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/holds', [
                'product_id' => 1,
                'qty' => 2 // Request 2 units each
            ]);

            if ($response->status() === 201) {
                $successCount++;
            } else {
                $errorMessages[] = $response->json('error');
            }
        }

        // With 5 total stock and requests of 2 units each:
        // - First request: 2 units → 3 left
        // - Second request: 2 units → 1 left  
        // - Third request: 2 units → should fail (only 1 left)
        // So max 2 successful requests
        $this->assertLessThanOrEqual(2, $successCount,
            "Should max 2 successful requests for 2 units each from 5 total stock. Got: {$successCount}");
        
        echo "Validation test:\n";
        echo "- Successful holds (2 units each): {$successCount}/10 (Max expected: 2)\n";
    }

    /**
     * Helper method to get stock via HTTP
     */
    private function getAvailableStockViaHttp($productId): int
    {
        $client = new Client(['base_uri' => 'http://localhost:8000', 'timeout' => 5]);
        try {
            $response = $client->get("/api/products/{$productId}");
            $data = json_decode($response->getBody(), true);
            return $data['available_stock'] ?? -1;
        } catch (\Exception $e) {
            return -1;
        }
    }
	
public function test_debug_database_state()
{
    // Check before
    echo "=== BEFORE Product Creation ===\n";
    echo "Products table exists: " . (\Schema::hasTable('products') ? 'YES' : 'NO') . "\n";
    echo "Product count: " . Product::count() . "\n";
    
    // Create product
    Product::create([
        'id' => 1,
        'name' => 'Debug Product',
        'price' => 1000,
        'stock' => 5,
        'sku' => 'DEBUG-' . time()
    ]);
    
    // Check after
    echo "\n=== AFTER Product Creation ===\n";
    echo "Product count: " . Product::count() . "\n";
    echo "Product with ID 1: " . (Product::find(6) ? 'FOUND' : 'NOT FOUND') . "\n";
    
    // Check database directly
    $results = \DB::select('SELECT * FROM products');
    echo "Raw SQL results count: " . count($results) . "\n";
    
    $this->assertTrue(true);
}
}