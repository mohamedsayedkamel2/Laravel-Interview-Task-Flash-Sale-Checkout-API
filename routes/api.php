<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HoldController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentWebhookController;

// -----------------------------
// Product APIs
// -----------------------------
Route::get('/products/{id}', [ProductController::class, 'show']);
Route::post('/products/{id}/refresh-stock', [ProductController::class, 'refreshStock']);
Route::get('/products/{id}/stock-breakdown', [ProductController::class, 'stockBreakdown']);

// -----------------------------
// Hold APIs
// -----------------------------
Route::post('/holds', [HoldController::class, 'store']);
Route::get('/holds/{holdId}', [HoldController::class, 'show']);
Route::delete('/holds/{holdId}', [HoldController::class, 'release']);

// -----------------------------
// Order APIs
// -----------------------------
Route::post('/orders', [OrderController::class, 'store']);
Route::get('/orders/{orderId}', [OrderController::class, 'show']);
Route::get('/debug-hold/{holdId}', [OrderController::class, 'debugHold']);

// Optional helper endpoint
Route::get('/orders/check-hold/{holdId}', [OrderController::class, 'checkHold']); 
Route::post('/orders/expire-hold/{holdId}', [OrderController::class, 'expireHold']); // For testing
Route::get('/orders', [OrderController::class, 'index']); // For debugging

// -----------------------------
// Payment Webhook
// -----------------------------
Route::post('/payments/webhook', [PaymentWebhookController::class, 'handle']);
Route::post('/payments/process-pending/{orderId}', [PaymentWebhookController::class, 'processPendingWebhooks']);