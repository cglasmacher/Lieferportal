<?php

namespace App\Http\Controllers;

use App\Domain\Pricing\QuoteCart;
use App\Http\Requests\QuoteRequest;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Restaurant::query()->where('is_published', true)->orderBy('name')->get()->map(fn ($r) => $this->summary($r))]);
    }

    public function show(string $slug): JsonResponse
    {
        $r = $this->find($slug);

        return response()->json(['data' => [
            ...$this->summary($r),
            'address' => $r->address, 'delivery_postcodes' => $r->delivery_postcodes,
            'menu_items' => $r->menuItems()->where('is_available', true)->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn ($item) => $item->only(['id', 'category', 'name', 'description', 'price_cents', 'allergens'])),
            'payment_methods' => [
                ['id' => 'stripe', 'label' => 'Karte / Wallet über Stripe', 'available' => false],
                ['id' => 'paypal', 'label' => 'PayPal', 'available' => false],
            ],
        ]]);
    }

    public function quote(QuoteRequest $request, string $slug, QuoteCart $cart): JsonResponse
    {
        return response()->json(['data' => $cart->calculate($this->find($slug), $request->validated())]);
    }

    private function find(string $slug): Restaurant
    {
        return Restaurant::query()->where('is_published', true)->where('slug', $slug)->firstOrFail();
    }

    private function summary(Restaurant $r): array
    {
        return $r->only(['slug', 'name', 'description', 'cuisine', 'city', 'is_accepting_orders',
            'minimum_order_cents', 'delivery_fee_cents', 'estimated_minutes']);
    }
}
