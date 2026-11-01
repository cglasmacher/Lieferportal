<?php

namespace Tests\Feature;

use App\Domain\Billing\MonthlyBilling;
use App\Domain\Billing\Plans;
use App\Models\MonthlyBillingDraft;
use App\Models\Restaurant;
use App\Models\RestaurantActivity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoCatalogSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyBillingTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private User $owner;

    private MonthlyBilling $billing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00', 'Europe/Berlin'));
        $this->seed(DemoCatalogSeeder::class);
        $this->restaurant = Restaurant::where('slug', 'demo-kueche')->firstOrFail();
        $this->owner = User::factory()->create();
        $this->owner->restaurants()->attach($this->restaurant->id, ['role' => 'owner']);
        $this->billing = app(MonthlyBilling::class);
    }

    public function test_one_draft_per_month_with_saved_tariff_vat_and_recipient(): void
    {
        $term = $this->billing->agree($this->restaurant, 'starter', '2026-11');
        $draft = $this->billing->prepare($this->restaurant, '2026-11');
        $again = $this->billing->prepare($this->restaurant, '2026-11');
        $this->assertSame($draft->id, $again->id);
        $this->assertDatabaseCount('monthly_billing_drafts', 1);
        $this->assertSame($term->id, $draft->restaurant_billing_term_id);
        $this->assertSame(4900, $draft->net_cents);
        $this->assertSame(931, $draft->vat_cents);
        $this->assertSame(5831, $draft->gross_cents);
        $this->assertSame('2026-11-30', $draft->period_end->format('Y-m-d'));
        $this->assertSame($this->restaurant->name, $draft->recipient_snapshot['name']);
        $this->assertSame(1, RestaurantActivity::where('action', 'billing.draft_prepared')->count());
    }

    public function test_old_agreement_and_drafts_are_independent_of_later_config_changes(): void
    {
        $this->billing->agree($this->restaurant, 'starter', '2026-11');
        $draft = $this->billing->prepare($this->restaurant, '2026-11');
        config(['billing.plans.starter.monthly_net_cents' => 9900, 'platform.commission_vat_basis_points' => 2000]);
        $this->restaurant->update(['name' => 'Changed name', 'billing_plan' => 'pro']);
        $same = $this->billing->prepare($this->restaurant, '2026-11');
        $this->assertSame($draft->id, $same->id);
        $this->assertSame(5831, $same->gross_cents);
        $this->assertSame('Die Demo-Küche', $same->recipient_snapshot['name']);
        $this->travelTo(CarbonImmutable::parse('2026-12-15 12:00:00', 'Europe/Berlin'));
        $next = $this->billing->prepare($this->restaurant, '2026-12');
        $this->assertSame(4900, $next->net_cents);
        $this->assertSame(931, $next->vat_cents);
        $this->assertSame('Changed name', $next->recipient_snapshot['name']);
    }

    public function test_dated_terms_select_correct_month_and_commission_without_double_charging(): void
    {
        $this->billing->agree($this->restaurant, 'starter', '2026-11');
        $this->billing->agree($this->restaurant, 'flex', '2026-12');
        $this->restaurant->update(['billing_plan' => 'flex']);
        $this->assertSame(0, app(Plans::class)->forOrder($this->restaurant, 3000)['gross_cents']);
        $this->assertSame(4900, $this->billing->prepare($this->restaurant, '2026-11')->net_cents);
        $this->travelTo(CarbonImmutable::parse('2026-12-01 00:00:00', 'Europe/Berlin'));
        $this->assertSame(179, app(Plans::class)->forOrder($this->restaurant, 3000)['gross_cents']);
        $this->expectException(DomainException::class);
        $this->billing->prepare($this->restaurant, '2026-12');
    }

    public function test_future_agreement_does_not_start_flat_billing_early(): void
    {
        $this->billing->agree($this->restaurant, 'pro', '2026-12');
        $this->restaurant->update(['billing_plan' => 'pro']);
        $this->assertSame(179, app(Plans::class)->forOrder($this->restaurant, 3000)['gross_cents']);
        $this->assertSame('before_agreement', app(Plans::class)->effectiveRates($this->restaurant)['source']);
        $this->expectException(DomainException::class);
        $this->billing->prepare($this->restaurant, '2026-12');
    }

    public function test_no_draft_without_explicit_billing_start(): void
    {
        $this->restaurant->update(['billing_plan' => 'pro']);
        $this->expectException(DomainException::class);
        $this->billing->prepare($this->restaurant, '2026-11');
    }

    public function test_existing_terms_are_idempotent_but_conflicting_terms_are_rejected(): void
    {
        $one = $this->billing->agree($this->restaurant, 'starter', '2026-11');
        $this->assertSame($one->id, $this->billing->agree($this->restaurant, 'starter', '2026-11')->id);
        $this->assertDatabaseCount('restaurant_billing_terms', 1);
        $this->expectException(DomainException::class);
        $this->billing->agree($this->restaurant, 'growth', '2026-11');
    }

    public function test_invalid_or_backdated_new_terms_fail_without_partial_plan_changes(): void
    {
        foreach (['2026-13', '2026-1', 'garbage', '2026-10'] as $month) {
            $this->artisan('billing:set-plan', ['slug' => 'demo-kueche', 'plan' => 'starter', '--billing-from' => $month])->assertFailed();
        }
        $this->assertSame('flex', $this->restaurant->fresh()->billing_plan);
        $this->assertDatabaseCount('restaurant_billing_terms', 0);
        $this->assertDatabaseCount('restaurant_activities', 0);
    }

    public function test_operator_can_agree_and_prepare_without_duplicate_records(): void
    {
        $this->artisan('billing:set-plan', ['slug' => 'demo-kueche', 'plan' => 'growth', '--billing-from' => '2026-11'])->assertSuccessful();
        for ($i = 0; $i < 2; $i++) {
            $this->artisan('billing:prepare', ['slug' => 'demo-kueche', 'month' => '2026-11'])->assertSuccessful();
        }
        $this->assertDatabaseCount('monthly_billing_drafts', 1);
        $this->assertSame(10591, MonthlyBillingDraft::first()->gross_cents);
    }

    public function test_billing_history_and_draft_view_are_owner_only_and_restaurant_scoped(): void
    {
        $this->billing->agree($this->restaurant, 'starter', '2026-11');
        $draft = $this->billing->prepare($this->restaurant, '2026-11');
        $url = '/portal/restaurants/demo-kueche/billing-drafts/'.$draft->id;
        $this->get($url)->assertRedirect('/portal/login');
        $this->actingAs($this->owner)->get($url)->assertOk()->assertSee('keine Rechnung')->assertSee('58,31')->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/merchant/restaurants/demo-kueche/billing')->assertOk()->assertJsonCount(1, 'data.drafts')->assertJsonPath('data.drafts.0.month', '2026-11')->assertJsonCount(1, 'data.billing_terms');
        $other = $this->restaurant->replicate();
        $other->slug = 'other';
        $other->save();
        $this->owner->restaurants()->attach($other->id, ['role' => 'owner']);
        $this->get('/portal/restaurants/other/billing-drafts/'.$draft->id)->assertNotFound();
        $this->owner->restaurants()->updateExistingPivot($this->restaurant->id, ['role' => 'manager']);
        $this->get($url)->assertForbidden();
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
    }

    public function test_snapshot_cannot_be_silently_overwritten(): void
    {
        $this->billing->agree($this->restaurant, 'starter', '2026-11');
        $draft = $this->billing->prepare($this->restaurant, '2026-11');
        $this->expectException(DomainException::class);
        $draft->update(['gross_cents' => 1]);
    }

    public function test_month_boundary_uses_berlin_time_and_handles_leap_year(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-30 23:30:00', 'UTC'));
        $this->billing->agree($this->restaurant, 'starter', '2026-12');
        $this->assertSame(5831, $this->billing->prepare($this->restaurant,'2026-12')->gross_cents);
        $this->travelTo(CarbonImmutable::parse('2028-02-15 12:00:00','Europe/Berlin'));
        $this->billing->agree($this->restaurant,'pro','2028-02');
        $this->assertSame('2028-02-29',$this->billing->prepare($this->restaurant,'2028-02')->period_end->format('Y-m-d'));
    }
}
