<?php

namespace App\Domain\Billing;

use App\Models\MonthlyBillingDraft;
use App\Models\Restaurant;
use App\Models\RestaurantActivity;
use App\Models\RestaurantBillingTerm;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class MonthlyBilling
{
    public function month(string $month): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) || substr($month, 0, 4) < '2000') {
            throw new InvalidArgumentException('Monat im Format JJJJ-MM ab Jahr 2000 erforderlich.');
        }

        return CarbonImmutable::createFromFormat('!Y-m', $month, 'Europe/Berlin');
    }

    /** Caller can nest this in the tariff-assignment transaction. */
    public function agree(Restaurant $restaurant, string $planKey, string $month): RestaurantBillingTerm
    {
        $start = $this->month($month);
        $plan = app(Plans::class)->get($planKey);
        $values = ['plan_key' => $planKey, 'plan_name' => $plan['name'], 'monthly_net_cents' => $plan['monthly_net_cents'],
            'vat_basis_points' => config('platform.commission_vat_basis_points'), 'commission_basis_points' => $plan['commission_basis_points']];

        return DB::transaction(function () use ($restaurant, $start, $values) {
            Restaurant::whereKey($restaurant->id)->lockForUpdate()->firstOrFail();
            $existing = RestaurantBillingTerm::where('restaurant_id', $restaurant->id)->where('effective_month', $start->toDateString())->first();
            if ($existing) {
                foreach ($values as $key => $value) {
                    if ((string) $existing->$key !== (string) $value) {
                        throw new DomainException('Für diesen Startmonat sind bereits andere Bedingungen gespeichert.');
                    }
                }

                return $existing;
            }
            if ($start->lessThan(CarbonImmutable::now('Europe/Berlin')->startOfMonth())) {
                throw new DomainException('Neue Abrechnungsbedingungen dürfen nicht rückdatiert werden.');
            }
            $term = RestaurantBillingTerm::create(['restaurant_id' => $restaurant->id, 'effective_month' => $start->toDateString(), ...$values]);
            RestaurantActivity::create(['restaurant_id' => $restaurant->id, 'action' => 'billing.terms_agreed', 'metadata' => ['term_id' => $term->id, 'effective_month' => $start->format('Y-m'), 'plan' => $values['plan_key']]]);

            return $term;
        });
    }

    public function prepare(Restaurant $restaurant, string $month): MonthlyBillingDraft
    {
        $start = $this->month($month);
        if ($start->greaterThan(CarbonImmutable::now('Europe/Berlin')->startOfMonth())) {
            throw new DomainException('Entwürfe werden nur für aktuelle oder vergangene Monate erstellt.');
        }

        return DB::transaction(function () use ($restaurant, $start) {
            $locked = Restaurant::whereKey($restaurant->id)->lockForUpdate()->firstOrFail();
            $existing = MonthlyBillingDraft::where('restaurant_id', $locked->id)->where('period_month', $start->toDateString())->first();
            if ($existing) {
                return $existing;
            }
            $term = RestaurantBillingTerm::where('restaurant_id', $locked->id)->where('effective_month', '<=', $start->toDateString())->latest('effective_month')->first();
            if (! $term) {
                throw new DomainException('Für diesen Monat fehlen ausdrücklich vereinbarte Abrechnungsbedingungen.');
            }
            if ($term->commission_basis_points !== 0 || $term->monthly_net_cents <= 0) {
                throw new DomainException('Flex-Provisionen benötigen Bestelldaten; es wird keine fiktive Nullabrechnung erstellt.');
            }
            $vat = intdiv($term->monthly_net_cents * $term->vat_basis_points + 5000, 10000);
            $draft = MonthlyBillingDraft::create([
                'restaurant_id' => $locked->id, 'restaurant_billing_term_id' => $term->id,
                'period_month' => $start->toDateString(), 'period_end' => $start->endOfMonth()->toDateString(), 'currency' => 'EUR',
                'tariff_snapshot' => $term->only(['plan_key', 'plan_name', 'monthly_net_cents', 'vat_basis_points', 'commission_basis_points']),
                'recipient_snapshot' => $locked->only(['name', 'address', 'city']),
                'net_cents' => $term->monthly_net_cents, 'vat_cents' => $vat, 'gross_cents' => $term->monthly_net_cents + $vat,
            ]);
            RestaurantActivity::create(['restaurant_id' => $locked->id, 'action' => 'billing.draft_prepared', 'metadata' => ['draft_id' => $draft->id, 'month' => $start->format('Y-m')]]);

            return $draft;
        });
    }
}
