<?php

namespace App\Http\Controllers;

use App\Services\Product\ProductService;
use App\Services\Holds\HoldRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Exception;

class ProductController extends Controller
{
    private ProductService $productService;
    private HoldRepository $holdService;

    public function __construct(
        ProductService $productService,
        HoldRepository $holdService
    ) {
        $this->productService = $productService;
        $this->holdService = $holdService;
    }

    public function show($id): JsonResponse
    {
        try {
            $product = $this->productService->getProductWithStock($id);
            
            return response()->json($product);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product not found'], 404);
            
        } catch (Exception $e) {
            Log::error('Product fetch failed', [
                'product_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json(['error' => 'Failed to fetch product'], 500);
        }
    }

    public function refreshStock($id): JsonResponse
    {
        try {
            $result = $this->productService->recalculateStock($id);
            
            return response()->json($result);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product not found'], 404);
            
        } catch (Exception $e) {
            Log::error('Stock refresh failed', [
                'product_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json(['error' => 'Failed to refresh stock'], 500);
        }
    }

    public function stockBreakdown($id): JsonResponse
    {
        try {
            $result = $this->productService->getStockBreakdown($id);
            
            return response()->json($result);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product not found'], 404);
            
        } catch (Exception $e) {
            Log::error('Stock breakdown failed', [
                'product_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json(['error' => 'Failed to get stock breakdown'], 500);
        }
    }
}