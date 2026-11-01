<?php

namespace App\Domain\Billing;

use App\Domain\Pricing\Commission;
use App\Models\Restaurant;
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

    public function forOrder(Restaurant $restaurant, int $merchandiseCents): array
    {
        return (new Commission)->calculate($merchandiseCents, $this->get($restaurant->billing_plan)['commission_basis_points'], config('platform.commission_vat_basis_points'));
    }

    public function monthlyFee(Restaurant $restaurant): array
    {
        $net = $this->get($restaurant->billing_plan)['monthly_net_cents'];
        $vat = intdiv($net * config('platform.commission_vat_basis_points') + 5000, 10000);

        return ['net_cents' => $net, 'vat_cents' => $vat, 'gross_cents' => $net + $vat];
    }
}
