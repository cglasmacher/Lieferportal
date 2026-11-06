<?php

namespace Tests\Feature;

use App\Domain\Billing\MonthlyBilling;
use App\Domain\Billing\Referrals;
use App\Models\MonthlyBillingDraft;
use App\Models\Restaurant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralBillingTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $referrer;

    private MonthlyBilling $billing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'Europe/Berlin'));
        $this->artisan('restaurant:create', ['slug' => 'werber', 'name' => 'Werber'])->assertSuccessful();
        $this->artisan('referral:enable', ['slug' => 'werber', 'company-key' => 'FIRMA-001'])->assertSuccessful();
        $this->referrer = Restaurant::where('slug', 'werber')->firstOrFail();
        $this->billing = app(MonthlyBilling::class);
        $this->billing->agree($this->referrer, 'starter', '2026-11');
    }

    private function onboard(string $slug, string $plan = 'starter'): Restaurant
    {
        $this->artisan('restaurant:create', [
            'slug' => $slug, 'name' => $slug, '--company-key' => $slug, '--referral-code' => $this->referrer->referral_code,
        ])->assertSuccessful();
        $restaurant = Restaurant::where('slug', $slug)->firstOrFail();
        $this->billing->agree($restaurant, $plan, '2026-11');

        return $restaurant;
    }

    private function confirm(MonthlyBillingDraft $draft, string $reference, bool $reverse = false): void
    {
        $this->artisan('billing:confirm-payment', [
            'draft' => $draft->id, 'reference' => $reference, 'amount-cents' => $draft->gross_cents, '--reverse' => $reverse,
        ])->assertSuccessful();
    }

    public function test_attribution_is_only_for_new_independent_companies(): void
    {
        $arguments = [
            'slug' => 'filiale', 'name' => 'Filiale', '--company-key' => 'firma-001', '--referral-code' => $this->referrer->referral_code,
        ];
        $this->artisan('restaurant:create', $arguments)->assertFailed();
        $this->artisan('restaurant:create', [...$arguments, '--company-key' => 'FIRMA-002', '--referral-code' => 'ungültig'])->assertFailed();
        $new = $this->onboard('neukunde');
        $this->assertSame($this->referrer->id, $new->referred_by_restaurant_id);
        $this->artisan('restaurant:create', [...$arguments, '--company-key' => 'neukunde'])->assertFailed();
        $this->artisan('restaurant:create', [...$arguments, 'slug' => 'neukunde', '--company-key' => 'FIRMA-003'])->assertFailed();
        $this->assertDatabaseMissing('restaurants', ['slug' => 'filiale']);
    }

    public function test_two_paid_months_unlock_ten_euros_and_vat_uses_reduced_price(): void
    {
        $partner = $this->onboard('neukunde', 'growth');
        $this->confirm($this->billing->prepare($partner, '2026-11'), 'incoming-1');
        $november = $this->billing->prepare($this->referrer, '2026-11');
        $this->assertSame(4900, $november->net_cents);

        $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00', 'Europe/Berlin'));
        $this->confirm($this->billing->prepare($partner, '2026-12'), 'incoming-2');
        $december = $this->billing->prepare($this->referrer, '2026-12');
        $this->assertSame(3900, $december->net_cents);
        $this->assertSame(741, $december->vat_cents);
        $this->assertSame(4641, $december->gross_cents);
        $this->assertSame(1000, $december->referral_discount_cents);

        $owner = User::factory()->create();
        $owner->restaurants()->attach($this->referrer->id, ['role' => 'owner']);
        $this->actingAs($owner)->getJson('/api/v1/merchant/restaurants/werber/billing')
            ->assertOk()->assertJsonPath('data.referrals.qualified', 1)
            ->assertJsonPath('data.referrals.code', $this->referrer->referral_code)
            ->assertDontSee('incoming-2');
        $this->get('/portal/restaurants/werber/billing-drafts/'.$december->id)->assertOk()->assertSee('Empfehlungsrabatt');
        $owner->restaurants()->updateExistingPivot($this->referrer->id, ['role' => 'manager']);
        $this->getJson('/api/v1/merchant/restaurants/werber/billing')->assertForbidden();

        $this->travelTo(CarbonImmutable::parse('2027-01-05 12:00', 'Europe/Berlin'));
        $this->assertSame(4900, $this->billing->prepare($this->referrer, '2027-01')->net_cents);
        $this->assertSame(3900, $this->billing->prepare($this->referrer, '2026-12')->net_cents);
    }

    public function test_extra_partners_replace_reversed_payments_and_discount_stops_at_twenty_euros(): void
    {
        $partners = [];
        foreach (['firma-a', 'firma-b', 'firma-c'] as $slug) {
            $partners[] = $partner = $this->onboard($slug);
            $this->confirm($this->billing->prepare($partner, '2026-11'), $slug.'-11');
        }
        $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00', 'Europe/Berlin'));
        $payments = [];
        foreach ($partners as $partner) {
            $payments[] = $payment = $this->billing->prepare($partner, '2026-12');
            $this->confirm($payment, $partner->slug.'-12');
        }
        $referrals = app(Referrals::class);
        $month = $this->billing->month('2026-12');
        $this->assertSame(3, $referrals->qualifiedCount($this->referrer, $month));
        $this->confirm($payments[0], 'firma-a-12', reverse: true);
        $this->assertSame(2, $referrals->qualifiedCount($this->referrer, $month));
        $draft = $this->billing->prepare($this->referrer, '2026-12');
        $this->assertSame(2900, $draft->net_cents);
        $this->assertSame(3451, $draft->gross_cents);
        $this->assertSame(2000, $draft->referral_discount_cents);

        $this->confirm($payments[1], 'firma-b-12', reverse: true);
        $this->assertSame(1, $referrals->qualifiedCount($this->referrer, $month));
        $this->assertSame(2900, $this->billing->prepare($this->referrer, '2026-12')->net_cents);
        $this->assertSame(0, $referrals->discount('pro', 12900, 3));
        $this->assertSame(2000, $referrals->discount('starter', 4900, 3));
    }

    public function test_payment_confirmation_checks_amount_and_deduplicates_bank_reference(): void
    {
        $partner = $this->onboard('neukunde');
        $draft = $this->billing->prepare($partner, '2026-11');
        $this->artisan('billing:confirm-payment', [
            'draft' => $draft->id, 'reference' => 'same', 'amount-cents' => 1,
        ])->assertFailed();
        $this->confirm($draft, 'same');
        $this->confirm($draft, 'same');
        $this->assertDatabaseCount('billing_payment_confirmations', 1);
        $other = $this->billing->prepare($this->referrer, '2026-11');
        $this->artisan('billing:confirm-payment', [
            'draft' => $other->id, 'reference' => 'same', 'amount-cents' => $other->gross_cents,
        ])->assertFailed();
    }

    public function test_late_confirmation_cannot_create_a_historical_discount(): void
    {
        $partner = $this->onboard('neukunde');
        $this->confirm($this->billing->prepare($partner, '2026-11'), 'one');
        $this->travelTo(CarbonImmutable::parse('2027-01-02 12:00', 'Europe/Berlin'));
        $this->confirm($this->billing->prepare($partner, '2026-12'), 'late');
        $this->assertSame(4900, $this->billing->prepare($this->referrer, '2026-12')->net_cents);
    }
}
