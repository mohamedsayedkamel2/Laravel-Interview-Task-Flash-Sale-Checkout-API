<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\Product;

class ExpiredHoldSeeder extends Seeder
{
    public function run(): void
    {
        // Clear existing test data
        $keys = Redis::keys('hold:*');
        foreach ($keys as $key) {
            Redis::del(str_replace(config('database.redis.options.prefix'), '', $key));
        }
        
        $productKeys = Redis::keys('product_holds:*');
        foreach ($productKeys as $key) {
            Redis::del(str_replace(config('database.redis.options.prefix'), '', $key));
        }
        
        $activeHoldKeys = Redis::keys('active_holds:*');
        foreach ($activeHoldKeys as $key) {
            Redis::del(str_replace(config('database.redis.options.prefix'), '', $key));
        }

        // Get a product from database
        $product = Product::first();
        
        if (!$product) {
            $this->command->error('No products found. Please run ProductSeeder first.');
            return;
        }

        $productId = $product->id;

        // Create expired holds (5 items)
        for ($i = 0; $i < 5; $i++) {
            $holdId = 'expired-hold-' . Str::random(10);
            $expiredAt = Carbon::now()->subMinutes(5)->timestamp;
            $qty = rand(1, 3);
            
            Redis::hmset("hold:{$holdId}", [
                'product_id' => $productId,
                'qty' => $qty,
                'status' => 'active',
                'created_at' => Carbon::now()->subMinutes(10)->toISOString(),
                'expires_at' => Carbon::now()->subMinutes(5)->toISOString(),
                'expires_at_timestamp' => $expiredAt,
                'hold_id' => $holdId,
                'session_id' => 'session-' . Str::random(8),
                'user_id' => rand(1000, 9999),
            ]);
            
            Redis::sadd("product_holds:{$productId}", $holdId);
            Redis::incrby("active_holds:{$productId}", $qty);
        }

        // Create active holds (3 items)
        for ($i = 0; $i < 3; $i++) {
            $holdId = 'active-hold-' . Str::random(10);
            $expiresAt = Carbon::now()->addMinutes(5)->timestamp;
            $qty = rand(1, 2);
            
            Redis::hmset("hold:{$holdId}", [
                'product_id' => $productId,
                'qty' => $qty,
                'status' => 'active',
                'created_at' => Carbon::now()->toISOString(),
                'expires_at' => Carbon::now()->addMinutes(5)->toISOString(),
                'expires_at_timestamp' => $expiresAt,
                'hold_id' => $holdId,
                'session_id' => 'session-' . Str::random(8),
                'user_id' => rand(1000, 9999),
            ]);
            
            Redis::sadd("product_holds:{$productId}", $holdId);
            Redis::incrby("active_holds:{$productId}", $qty);
        }

        // Create some holds for other products
        $otherProducts = Product::skip(1)->take(2)->get();
        
        foreach ($otherProducts as $otherProduct) {
            for ($i = 0; $i < 2; $i++) {
                $holdId = 'hold-product-' . $otherProduct->id . '-' . Str::random(8);
                $expiresAt = Carbon::now()->addMinutes(rand(5, 15))->timestamp;
                $qty = rand(1, 2);
                
                Redis::hmset("hold:{$holdId}", [
                    'product_id' => $otherProduct->id,
                    'qty' => $qty,
                    'status' => 'active',
                    'created_at' => Carbon::now()->toISOString(),
                    'expires_at' => Carbon::now()->addMinutes(rand(5, 15))->toISOString(),
                    'expires_at_timestamp' => $expiresAt,
                    'hold_id' => $holdId,
                    'session_id' => 'session-' . Str::random(8),
                    'user_id' => rand(1000, 9999),
                ]);
                
                Redis::sadd("product_holds:{$otherProduct->id}", $holdId);
                Redis::incrby("active_holds:{$otherProduct->id}", $qty);
            }
        }

        $this->command->info('Redis holds created:');
        $this->command->info('- 5 expired holds for product 1');
        $this->command->info('- 3 active holds for product 1');
        $this->command->info('- 2 active holds each for products 2 and 3');
    }
}