<?php

namespace App\Http\Controllers;

use App\Domain\Billing\Plans;
use App\Models\Restaurant;
use App\Models\RestaurantActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MerchantBillingController extends Controller
{
    public function show(Request $request, string $slug, Plans $plans): JsonResponse
    {
        $r = $this->owner($request, $slug);

        return response()->json(['data' => [
            'current_plan' => $plans->get($r->billing_plan),
            'plans' => array_map(fn ($key) => $plans->get($key), array_keys(config('billing.plans'))),
            'monthly_fee' => $plans->monthlyFee($r),
            'requested_plan' => $r->requested_billing_plan,
            'currency' => 'EUR', 'period' => 'month', 'scope' => 'restaurant_location',
            'billing_live' => false,
        ]]);
    }

    public function requestChange(Request $request, string $slug): JsonResponse
    {
        $r = $this->owner($request, $slug);
        $data = $request->validate(['plan' => ['required', Rule::in(array_keys(config('billing.plans')))]]);
        DB::transaction(function () use ($r, $data, $request) {
            $locked = Restaurant::whereKey($r->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->billing_plan === $data['plan'], 422, 'Dieser Tarif ist bereits aktiv.');
            if ($locked->requested_billing_plan === $data['plan']) {
                return;
            }
            $locked->update(['requested_billing_plan' => $data['plan'], 'billing_requested_at' => now(), 'billing_requested_by' => $request->user()->id]);
            RestaurantActivity::create(['restaurant_id' => $r->id, 'user_id' => $request->user()->id, 'action' => 'billing.requested', 'metadata' => ['plan' => $data['plan']]]);
        });

        return response()->json(['data' => ['requested' => true, 'message' => 'Tarifanfrage gespeichert. Noch kein Tarifwechsel oder Zahlungseinzug.']]);
    }

    public function cancel(Request $request, string $slug): JsonResponse
    {
        $r = $this->owner($request, $slug);
        DB::transaction(function () use ($r, $request) {
            $locked = Restaurant::whereKey($r->id)->lockForUpdate()->firstOrFail();
            if ($locked->requested_billing_plan !== null) {
                RestaurantActivity::create(['restaurant_id' => $r->id, 'user_id' => $request->user()->id, 'action' => 'billing.request_cancelled', 'metadata' => ['plan' => $locked->requested_billing_plan]]);
                $locked->update(['requested_billing_plan' => null, 'billing_requested_at' => null, 'billing_requested_by' => null]);
            }
        });

        return response()->json(['data' => ['cancelled' => true]]);
    }

    private function owner(Request $request, string $slug): Restaurant
    {
        $r = $request->user()->restaurants()->where('slug', $slug)->firstOrFail();
        abort_unless($r->pivot->role === 'owner', 403);

        return $r;
    }
}
