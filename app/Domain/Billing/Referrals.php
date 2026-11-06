<?php

namespace App\Domain\Billing;

use App\Models\Restaurant;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Referrals
{
    public function identity(string $key): string
    {
        $key = strtoupper(trim($key));
        if (! preg_match('/\A[A-Z0-9][A-Z0-9._-]{2,79}\z/', $key)) {
            throw new DomainException('Eine geprüfte Unternehmenskennung mit 3 bis 80 Zeichen ist erforderlich.');
        }

        return $key;
    }

    public function newCode(): string
    {
        return Str::upper(Str::random(16));
    }

    public function qualifiedCount(Restaurant $restaurant, CarbonImmutable $month): int
    {
        $start = $month->startOfMonth();
        $cutoff = $start->addMonth()->utc()->toDateTimeString();
        $partners = DB::table('monthly_billing_drafts as drafts')
            ->join('restaurants as partners', 'partners.id', '=', 'drafts.restaurant_id')
            ->join('billing_payment_confirmations as payments', 'payments.monthly_billing_draft_id', '=', 'drafts.id')
            ->where('partners.referred_by_restaurant_id', $restaurant->id)
            ->where('drafts.period_month', '<=', $start->toDateString())
            ->where('payments.confirmed_at', '<', $cutoff)
            ->where(fn ($query) => $query->whereNull('payments.reversed_at')->orWhere('payments.reversed_at', '>=', $cutoff))
            ->select('drafts.restaurant_id')
            ->groupBy('drafts.restaurant_id')
            ->havingRaw('COUNT(DISTINCT drafts.period_month) >= 2')
            ->havingRaw('MAX(drafts.period_month) = ?', [$start->toDateString()]);

        return DB::query()->fromSub($partners, 'qualified_partners')->count();
    }

    public function discount(string $plan, int $monthlyNetCents, int $qualified): int
    {
        if ($plan !== 'starter') {
            return 0;
        }

        return min(min(2, $qualified) * 1000, max(0, $monthlyNetCents - 2900));
    }
}
