<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\MerchantBillingController;
use App\Http\Controllers\MerchantRestaurantController;
use App\Http\Controllers\MerchantSessionController;
use App\Models\Restaurant;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware('throttle:60,1')->group(function () {
    Route::get('/restaurants', [CatalogController::class, 'index']);
    Route::get('/restaurants/{slug}', [CatalogController::class, 'show']);
    // Same-origin web middleware intentionally retains CSRF protection.
    Route::post('/restaurants/{slug}/quote', [CatalogController::class, 'quote'])->middleware('throttle:20,1');
});
Route::view('/', 'app');
Route::get('/r/{slug}', function (string $slug) {
    abort_unless(Restaurant::where('slug', $slug)->where('is_published', true)->exists(), 404);

    return view('app');
});

Route::view('/portal/login', 'merchant')->middleware('guest')->name('login');
Route::view('/portal', 'merchant')->middleware('auth')->name('merchant');
Route::post('/api/v1/merchant/login', [MerchantSessionController::class, 'store'])
    ->middleware(['guest', 'throttle:30,1']);
Route::prefix('/api/v1/merchant')->middleware(['auth', 'throttle:60,1'])->group(function () {
    Route::get('/session', [MerchantSessionController::class, 'show']);
    Route::post('/logout', [MerchantSessionController::class, 'destroy']);
    Route::get('/restaurants/{slug}', [MerchantRestaurantController::class, 'show']);
    Route::patch('/restaurants/{slug}', [MerchantRestaurantController::class, 'update']);
    Route::post('/restaurants/{slug}/menu-items', [MerchantRestaurantController::class, 'storeMenuItem']);
    Route::put('/restaurants/{slug}/menu-items/{item}', [MerchantRestaurantController::class, 'updateMenuItem'])->whereNumber('item');
});

Route::prefix('/api/v1/merchant')->middleware(['auth', 'throttle:60,1'])->group(function () {
    Route::get('/restaurants/{slug}/billing', [MerchantBillingController::class, 'show']);
    Route::post('/restaurants/{slug}/billing/request', [MerchantBillingController::class, 'requestChange']);
    Route::delete('/restaurants/{slug}/billing/request', [MerchantBillingController::class, 'cancel']);
    Route::get('/restaurants/{slug}/menu-export', [MerchantRestaurantController::class, 'export']);
    Route::get('/restaurants/{slug}/activities', [MerchantRestaurantController::class, 'activities']);
});
