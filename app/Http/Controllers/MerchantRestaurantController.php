<?php

namespace App\Http\Controllers;

use App\Domain\Billing\Plans;
use App\Http\Requests\SaveMenuItemRequest;
use App\Models\Restaurant;
use App\Models\RestaurantActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MerchantRestaurantController extends Controller
{
    public function show(Request $request, string $slug): JsonResponse
    {
        $r = $this->restaurant($request, $slug);

        return response()->json(['data' => [
            ...$r->only(['slug', 'name', 'is_published', 'is_accepting_orders', 'estimated_minutes', 'minimum_order_cents', 'delivery_fee_cents', 'delivery_postcodes']),
            'role' => $r->pivot->role,
            'billing_plan' => $r->billing_plan,
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
        DB::transaction(function () use ($r, $data, $request) {
            $r->update($data);
            $this->record($r, $request, 'settings.updated', []);
        });

        return response()->json(['data' => ['saved' => true]]);
    }

    public function storeMenuItem(SaveMenuItemRequest $request, string $slug): JsonResponse
    {
        $r = $this->restaurant($request, $slug, true);
        $item = DB::transaction(function () use ($r, $request) {
            $item = $r->menuItems()->create($request->validated());
            $this->record($r, $request, 'menu.created', ['item_id' => $item->id]);

            return $item;
        });

        return response()->json(['data' => ['id' => $item->id]], 201);
    }

    public function updateMenuItem(SaveMenuItemRequest $request, string $slug, int $item): JsonResponse
    {
        $r = $this->restaurant($request, $slug, true);
        DB::transaction(function () use ($r, $request, $item) {
            $r->menuItems()->findOrFail($item)->update($request->validated());
            $this->record($r, $request, 'menu.updated', ['item_id' => $item]);
        });

        return response()->json(['data' => ['saved' => true]]);
    }

    public function export(Request $request, string $slug): StreamedResponse
    {
        $r = $this->restaurant($request, $slug, true);
        abort_unless(app(Plans::class)->get($r->billing_plan)['menu_export'], 403, 'Der Speisekartenexport ist ab Wachstum enthalten.');

        return response()->streamDownload(function () use ($r) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Kategorie', 'Name', 'Beschreibung', 'Preis in Cent', 'Allergene', 'Verfügbar'], ';', '"', '');
            foreach ($r->menuItems()->orderBy('sort_order')->orderBy('id')->cursor() as $item) {
                $row = [$item->category, $item->name, $item->description, $item->price_cents, implode(', ', $item->allergens), $item->is_available ? 'Ja' : 'Nein'];
                $row = array_map(fn ($v) => preg_match('/^[\s]*[=+@-]/u', (string) $v) ? "'".$v : $v, $row);
                fputcsv($out, $row, ';', '"', '');
            }
            fclose($out);
        }, 'speisekarte.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function activities(Request $request, string $slug): JsonResponse
    {
        $r = $this->restaurant($request, $slug, true);
        abort_unless(app(Plans::class)->get($r->billing_plan)['activity_log'], 403, 'Das Änderungsprotokoll ist in Pro enthalten.');

        return response()->json(['data' => RestaurantActivity::where('restaurant_id', $r->id)->latest('id')->limit(50)->get(['id', 'action', 'metadata', 'created_at'])]);
    }

    private function record(Restaurant $r, Request $request, string $action, array $metadata): void
    {
        RestaurantActivity::create(['restaurant_id' => $r->id, 'user_id' => $request->user()->id, 'action' => $action, 'metadata' => $metadata]);
    }

    private function restaurant(Request $request, string $slug, bool $write = false): Restaurant
    {
        $r = $request->user()->restaurants()->where('slug', $slug)->firstOrFail();
        abort_if($write && ! in_array($r->pivot->role, ['owner', 'manager'], true), 403);

        return $r;
    }
}
