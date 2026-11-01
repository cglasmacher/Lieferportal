<?php

namespace App\Domain\Billing;

use App\Domain\Pricing\Commission;
use App\Models\Restaurant;
use App\Models\RestaurantBillingTerm;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class Plans
{
    public function get(string $key): array
    {
        $plan = config('billing.plans')[$key] ?? null;
        if (! is_array($plan)) {
            throw new InvalidArgumentException('Unbekannter Tarif.');
        }

        return ['key' => $key, ...$plan];
    }

    public function effectiveRates(Restaurant $restaurant): array
    {
        $terms = RestaurantBillingTerm::where('restaurant_id', $restaurant->id);
        $active = (clone $terms)->where('effective_month', '<=', CarbonImmutable::now('Europe/Berlin')->startOfMonth()->toDateString())->latest('effective_month')->first();
        if ($active) {
            return [...$active->only(['plan_key', 'plan_name', 'monthly_net_cents', 'vat_basis_points', 'commission_basis_points']), 'source' => 'agreement'];
        }
        $future = $terms->exists();
        $plan = $this->get($future ? 'flex' : $restaurant->billing_plan);

        return ['plan_key' => $plan['key'], 'plan_name' => $plan['name'], 'monthly_net_cents' => $plan['monthly_net_cents'],
            'commission_basis_points' => $plan['commission_basis_points'], 'vat_basis_points' => config('platform.commission_vat_basis_points'),
            'source' => $future ? 'before_agreement' : 'configuration'];
    }

    public function forOrder(Restaurant $restaurant, int $merchandiseCents): array
    {
        $rates = $this->effectiveRates($restaurant);

        return (new Commission)->calculate($merchandiseCents, $rates['commission_basis_points'], $rates['vat_basis_points']);
    }

    public function monthlyFee(Restaurant $restaurant): array
    {
        $net = $this->get($restaurant->billing_plan)['monthly_net_cents'];
        $vat = intdiv($net * config('platform.commission_vat_basis_points') + 5000, 10000);

        return ['net_cents' => $net, 'vat_cents' => $vat, 'gross_cents' => $net + $vat];
    }
}
