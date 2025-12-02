<?php

namespace App\Services\Stock;

use Illuminate\Support\Facades\Redis;

class ProductStockCache
{
    private int $productId;

    public function __construct(int $productId)
    {
        $this->productId = $productId;
    }

    public function availableKey(): string     { return "available_stock:{$this->productId}"; }
    public function reservedKey(): string      { return "reserved_stock:{$this->productId}"; }
    public function versionKey(): string       { return "stock_version:{$this->productId}"; }
    public function initializedKey(): string   { return "stock_initialized:{$this->productId}"; }
    public function holdsSetKey(): string      { return "product_holds:{$this->productId}"; }
}
