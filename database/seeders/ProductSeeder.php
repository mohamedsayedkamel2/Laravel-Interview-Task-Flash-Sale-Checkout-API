<?php

namespace Database\Seeders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run()
    {
        $productId = DB::table('products')->insertGetId([
            'name' => 'PS5 Flash Sale Edition',
            'price' => 499,
            'stock' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Initialize Redis available stock
        Redis::set("available_stock:$productId", 5);
    }
}
