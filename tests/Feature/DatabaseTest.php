<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;

class DatabaseTest extends TestCase
{
    use DatabaseTransactions;

    public function test_database_connection_and_products_table(): void
    {
        // Check if products table exists
        $this->assertTrue(
            Schema::hasTable('products'),
            'Products table should exist in the database'
        );
        
        // Check table structure
        $columns = Schema::getColumnListing('products');
        $this->assertContains('id', $columns);
        $this->assertContains('name', $columns);
        $this->assertContains('price', $columns);
        $this->assertContains('stock', $columns);
        
        echo "Products table columns: " . implode(', ', $columns) . "\n";
    }
    
    public function test_can_create_and_read_product(): void
    {
        // Skip if products table doesn't exist
        if (!Schema::hasTable('products')) {
            $this->markTestSkipped('Products table does not exist');
            return;
        }
        
        // Count existing products
        $initialCount = Product::count();
        echo "Initial product count: $initialCount\n";
        
        // Create a new product
        $product = Product::create([
            'name' => 'Flash Sale Test Product',
            'price' => 9999,
            'stock' => 50,
        ]);
        
        // Verify product was created
        $this->assertTrue($product->exists, 'Product should exist in database');
        $this->assertNotNull($product->id, 'Product should have an ID');
        
        // Verify product data
        $this->assertEquals('Flash Sale Test Product', $product->name);
        $this->assertEquals(9999, $product->price);
        $this->assertEquals(50, $product->stock);
        
        // Verify we can retrieve it
        $retrievedProduct = Product::find($product->id);
        $this->assertNotNull($retrievedProduct, 'Should be able to retrieve product');
        $this->assertEquals($product->name, $retrievedProduct->name);
        
        // Check final count
        $finalCount = Product::count();
        echo "Final product count: $finalCount\n";
        $this->assertEquals($initialCount + 1, $finalCount, 'Product count should increase by 1');
        
        echo "✅ Successfully created product: {$product->id} - {$product->name}\n";
    }
    
    public function test_product_model_structure(): void
    {
        // Check if model exists
        $this->assertTrue(class_exists(Product::class), 'Product model should exist');
        
        // Check fillable attributes
        $product = new Product();
        $fillable = $product->getFillable();
        
        echo "Product fillable attributes: " . implode(', ', $fillable) . "\n";
        
        $this->assertContains('name', $fillable);
        $this->assertContains('price', $fillable);
        $this->assertContains('stock', $fillable);
        
        // Test mass assignment
        $data = [
            'name' => 'Test Mass Assignment',
            'price' => 4999,
            'stock' => 25,
        ];
        
        $product = Product::create($data);
        
        $this->assertEquals($data['name'], $product->name);
        $this->assertEquals($data['price'], $product->price);
        $this->assertEquals($data['stock'], $product->stock);
        
        echo "✅ Mass assignment works correctly\n";
    }
}