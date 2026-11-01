<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveMenuItemRequest;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MerchantRestaurantController extends Controller
{
    public function show(Request $request, string $slug): JsonResponse
    {
        $r = $this->restaurant($request, $slug);

        return response()->json(['data' => [
            ...$r->only(['slug', 'name', 'is_published', 'is_accepting_orders', 'estimated_minutes', 'minimum_order_cents', 'delivery_fee_cents', 'delivery_postcodes']),
            'role' => $r->pivot->role,
            'can_edit' => in_array($r->pivot->role, ['owner', 'manager'], true),
            'menu_items' => $r->menuItems()->orderBy('sort_order')->orderBy('id')->get()->map(fn ($item) => $item->only([
                'id', 'name', 'description', 'category', 'price_cents', 'allergens', 'is_available', 'sort_order',
            ])),
        ]]);
    }

    public function update(Request $request, string $slug): JsonResponse
    {
        $r = $this->restaurant($request, $slug, true);
        $data = $request->validate([
            'is_accepting_orders' => ['required', 'boolean'],
            'estimated_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'minimum_order_cents' => ['required', 'integer', 'min:0', 'max:100000'],
            'delivery_fee_cents' => ['required', 'integer', 'min:0', 'max:10000'],
            'delivery_postcodes' => ['present', 'array', 'max:100'],
            'delivery_postcodes.*' => ['required', 'string', 'regex:/^[0-9]{5}$/', 'distinct'],
        ]);
        $r->update($data);

        return response()->json(['data' => ['saved' => true]]);
    }

    public function storeMenuItem(SaveMenuItemRequest $request, string $slug): JsonResponse
    {
        $r = $this->restaurant($request, $slug, true);
        $item = $r->menuItems()->create($request->validated());

        return response()->json(['data' => ['id' => $item->id]], 201);
    }

    public function updateMenuItem(SaveMenuItemRequest $request, string $slug, int $item): JsonResponse
    {
        $r = $this->restaurant($request, $slug, true);
        $r->menuItems()->findOrFail($item)->update($request->validated());

        return response()->json(['data' => ['saved' => true]]);
    }

    private function restaurant(Request $request, string $slug, bool $write = false): Restaurant
    {
        $r = $request->user()->restaurants()->where('slug', $slug)->firstOrFail();
        abort_if($write && ! in_array($r->pivot->role, ['owner', 'manager'], true), 403);

        return $r;
    }
}
