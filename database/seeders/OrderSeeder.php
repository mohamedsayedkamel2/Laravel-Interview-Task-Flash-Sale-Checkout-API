<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Str;
use Carbon\Carbon;

class OrderSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        Order::truncate();
        OrderItem::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $products = Product::all();
        $states = ['pending', 'processing', 'completed', 'cancelled'];

        // Create completed orders
        for ($i = 0; $i < 10; $i++) {
            $order = Order::create([
                'hold_id' => Str::uuid(),
                'state' => 'completed',
                'created_at' => Carbon::now()->subDays(rand(1, 30)),
            ]);

            // Add 1-3 random products to each order
            $productCount = rand(1, min(3, count($products)));
            $selectedProducts = $products->random($productCount);

            foreach ($selectedProducts as $product) {
                $qty = rand(1, 3);
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'qty' => $qty,
                    'price' => $product->price,
                ]);
            }
        }

        // Create pending/processing orders with active holds
        for ($i = 0; $i < 5; $i++) {
            $holdId = 'mysql-active-hold-' . Str::random(10);
            $state = $states[array_rand([0, 1])]; // pending or processing
            
            $order = Order::create([
                'hold_id' => $holdId,
                'state' => $state,
                'created_at' => Carbon::now()->subMinutes(rand(1, 30)),
            ]);

            // Add 1-2 random products
            $productCount = rand(1, min(2, count($products)));
            $selectedProducts = $products->random($productCount);

            foreach ($selectedProducts as $product) {
                $qty = rand(1, 2);
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'qty' => $qty,
                    'price' => $product->price,
                ]);
            }
        }

        $this->command->info('Created 15 orders with various states.');
    }
}