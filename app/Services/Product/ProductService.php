<?php

namespace App\Services\Product;

use App\Models\Product;
use App\Services\Stock\StockService;
use App\Services\Holds\HoldRepository;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Exception;

class ProductService
{
    private StockService $stockService;
    private HoldRepository $holdService;

    public function __construct(StockService $stockService, HoldRepository $holdService)
    {
        $this->stockService = $stockService;
        $this->holdService = $holdService;
    }

    public function getProductWithStock(int $productId): array
    {
        $product = $this->findProductOrFail($productId);
        $stock = $this->stockService->getStock($productId);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'price' => $product->price,
            'total_stock' => $product->stock,
            'available_stock' => $stock['available_stock'],
            'reserved_stock' => $stock['reserved_stock'],
            'active_holds' => $this->holdService->countActiveHolds($productId),
            'version' => $stock['version']
        ];
    }

    public function getStockBreakdown(int $productId): array
    {
        $product = $this->findProductOrFail($productId);
        $stock = $this->stockService->getStock($productId);
        
        return [
            'product_id' => $productId,
            'total_stock' => $product->stock,
            'available_stock' => $stock['available_stock'],
            'reserved_stock' => $stock['reserved_stock'],
            'active_holds_count' => $this->holdService->countActiveHolds($productId),
            'holds' => $this->holdService->listProductHolds($productId),
            'version' => $stock['version']
        ];
    }

    public function recalculateStock(int $productId): array
    {
        $product = $this->findProductOrFail($productId);
        
        $holds = $this->holdService->listProductHolds($productId);
        $totalReserved = 0;
        
        foreach ($holds as $hold) {
            if ($hold['status'] === 'active') {
                $totalReserved += (int) ($hold['qty'] ?? 0);
            }
        }

        $availableStock = max(0, $product->stock - $totalReserved);
        
        Redis::set("available_stock:{$productId}", $availableStock);
        Redis::set("reserved_stock:{$productId}", $totalReserved);
        Redis::set("active_holds:{$productId}", count($holds));
        Redis::incr("stock_version:{$productId}");

        return [
            'product_id' => $productId,
            'total_stock' => $product->stock,
            'available_stock' => $availableStock,
            'reserved_stock' => $totalReserved,
            'active_holds_count' => count($holds)
        ];
    }

    private function findProductOrFail(int $productId): Product
    {
        $product = Product::find($productId);
        
        if (!$product) {
            throw new ModelNotFoundException("Product not found");
        }
        
        return $product;
    }

    public function checkStockConsistency(int $productId): array
    {
        $product = $this->findProductOrFail($productId);
        $stock = $this->stockService->getStock($productId);
        
        $calculatedAvailable = $product->stock - $stock['reserved_stock'];
        $consistent = $stock['available_stock'] == $calculatedAvailable;
        
        if (!$consistent) {
            Log::warning("Stock inconsistency detected", [
                'product_id' => $productId,
                'redis_available' => $stock['available_stock'],
                'calculated_available' => $calculatedAvailable
            ]);
        }
        
        return [
            'consistent' => $consistent,
            'redis_available' => $stock['available_stock'],
            'calculated_available' => $calculatedAvailable,
            'reserved_stock' => $stock['reserved_stock'],
            'base_stock' => $product->stock
        ];
    }
}