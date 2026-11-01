<?php

use App\Http\Controllers\CatalogController;
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
