<?php

namespace App\Http\Controllers;

use App\Domain\Billing\Plans;
use App\Domain\Billing\Referrals;
use App\Models\MonthlyBillingDraft;
use App\Models\Restaurant;
use App\Models\RestaurantActivity;
use App\Models\RestaurantBillingTerm;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MerchantBillingController extends Controller
{
    public function show(Request $request, string $slug, Plans $plans, Referrals $referrals): JsonResponse
    {
        $r = $this->owner($request, $slug);

        $month = CarbonImmutable::now('Europe/Berlin')->startOfMonth();
        $qualified = $referrals->qualifiedCount($r, $month);
        $rates = $plans->effectiveRates($r);

        return response()->json(['data' => [
            'referrals' => [
                'code' => $r->referral_code,
                'month' => $month->format('Y-m'),
                'total' => Restaurant::where('referred_by_restaurant_id', $r->id)->count(),
                'qualified' => $qualified,
                'discount_cents' => $referrals->discount($rates['plan_key'], $rates['monthly_net_cents'], $qualified),
            ],
            'current_plan' => $plans->get($r->billing_plan),
            'plans' => array_map(fn ($key) => $plans->get($key), array_keys(config('billing.plans'))),
            'monthly_fee' => $plans->monthlyFee($r),
            'effective_rates' => $rates,
            'requested_plan' => $r->requested_billing_plan,
            'currency' => 'EUR', 'period' => 'month', 'scope' => 'restaurant_location',
            'billing_live' => false,
            'billing_terms' => RestaurantBillingTerm::where('restaurant_id', $r->id)->latest('effective_month')->limit(12)->get(['effective_month', 'plan_name', 'monthly_net_cents']),
            'drafts' => MonthlyBillingDraft::where('restaurant_id', $r->id)->latest('period_month')->limit(24)->get()->map(fn ($d) => [
                'id' => $d->id, 'month' => $d->period_month->format('Y-m'), 'plan_name' => $d->tariff_snapshot['plan_name'],
                'referral_discount_cents' => $d->referral_discount_cents,
                'net_cents' => $d->net_cents, 'vat_cents' => $d->vat_cents, 'gross_cents' => $d->gross_cents,
            ]),
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

    public function draft(Request $request, string $slug, string $draft): Response
    {
        $r = $this->owner($request, $slug);
        $document = MonthlyBillingDraft::where('restaurant_id', $r->id)->findOrFail($draft);

        return response()->view('billing-draft', ['draft' => $document])->header('Cache-Control', 'private, no-store');
    }

    private function owner(Request $request, string $slug): Restaurant
    {
        $r = $request->user()->restaurants()->where('slug', $slug)->firstOrFail();
        abort_unless($r->pivot->role === 'owner', 403);

        return $r;
    }
}
