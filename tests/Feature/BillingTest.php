<?php

namespace Tests\Feature;

use App\Domain\Billing\Plans;
use App\Domain\Payments\PaymentDraft;
use App\Domain\Payments\PaypalMerchantOrder;
use App\Domain\Payments\StripeDirectCharge;
use App\Models\Restaurant;
use App\Models\RestaurantActivity;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $r;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoCatalogSeeder::class);
        $this->r = Restaurant::where('slug', 'demo-kueche')->firstOrFail();
        $this->owner = User::factory()->create();
        $this->owner->restaurants()->attach($this->r->id, ['role' => 'owner']);
    }

    private function path(string $suffix = ''): string
    {
        return '/api/v1/merchant/restaurants/demo-kueche/billing'.$suffix;
    }

    public function test_tariffs_are_alternatives_not_added_to_commission(): void
    {
        $plans = app(Plans::class);
        foreach (['starter' => 4900, 'growth' => 8900, 'pro' => 12900] as $key => $net) {
            $this->r->billing_plan = $key;
            $this->assertSame(0, $plans->forOrder($this->r, 100000)['gross_cents']);
            $fee = $plans->monthlyFee($this->r);
            $this->assertSame($net, $fee['net_cents']);
            $this->assertSame(intdiv($net * 19 + 50, 100), $fee['vat_cents']);
            $this->assertSame($fee['net_cents'] + $fee['vat_cents'], $fee['gross_cents']);
        }
        $this->r->billing_plan = 'flex';
        $this->assertSame(0, $plans->monthlyFee($this->r)['gross_cents']);
        $this->assertSame(179, $plans->forOrder($this->r, 3000)['gross_cents']);
    }

    public function test_flat_rate_payments_do_not_include_zero_platform_fees(): void
    {
        $draft = new PaymentDraft('test-order', 3000, 0);
        $this->assertArrayNotHasKey('application_fee_amount', (new StripeDirectCharge)->build($draft, 'acct_test')['params']);
        $this->assertArrayNotHasKey('payment_instruction', (new PaypalMerchantOrder)->build($draft, 'ABC1234567890')['body']['purchase_units'][0]);
    }

    public function test_unknown_plan_does_not_fall_back_to_free_or_flex(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(Plans::class)->get('starter.planned_benefits');
    }

    public function test_only_owner_can_read_and_request_billing_changes(): void
    {
        $this->getJson($this->path())->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson($this->path())->assertNotFound();
        $this->owner->restaurants()->updateExistingPivot($this->r->id, ['role' => 'manager']);
        $this->actingAs($this->owner)->getJson($this->path())->assertForbidden();
        $this->postJson($this->path('/request'), ['plan' => 'pro'])->assertForbidden();
        $this->owner->restaurants()->updateExistingPivot($this->r->id, ['role' => 'owner']);
        $this->getJson($this->path())->assertOk()->assertJsonPath('data.current_plan.key', 'flex')->assertJsonPath('data.billing_live', false)->assertJsonCount(4, 'data.plans');
    }

    public function test_request_is_idempotent_and_cannot_change_active_tariff(): void
    {
        $this->actingAs($this->owner);
        for ($i = 0; $i < 2; $i++) {
            $this->postJson($this->path('/request'), ['plan' => 'starter'])->assertOk();
        }
        $this->assertSame('flex', $this->r->fresh()->billing_plan);
        $this->assertSame('starter', $this->r->fresh()->requested_billing_plan);
        $this->assertSame(1, RestaurantActivity::where('action', 'billing.requested')->count());
        $this->deleteJson($this->path('/request'))->assertOk();
        $this->assertNull($this->r->fresh()->requested_billing_plan);
        $this->postJson($this->path('/request'), ['plan' => 'invalid'])->assertUnprocessable();
        $this->postJson($this->path('/request'), ['plan' => 'flex'])->assertUnprocessable();
    }

    public function test_operator_sets_plan_and_records_history_without_invoicing(): void
    {
        $this->artisan('billing:set-plan', ['slug' => 'demo-kueche', 'plan' => 'growth'])->assertSuccessful();
        $this->assertSame('growth', $this->r->fresh()->billing_plan);
        $this->assertDatabaseHas('restaurant_activities', ['restaurant_id' => $this->r->id, 'action' => 'billing.changed']);
        $this->artisan('billing:set-plan', ['slug' => 'demo-kueche', 'plan' => 'invalid'])->assertFailed();
        $this->assertSame('growth', $this->r->fresh()->billing_plan);
    }

    public function test_seat_limits_block_extra_access_and_unsafe_downgrades(): void
    {
        $this->artisan('billing:set-plan', ['slug' => 'demo-kueche', 'plan' => 'starter'])->assertSuccessful();
        $second = User::factory()->create();
        $third = User::factory()->create();
        $this->artisan('merchant:grant', ['slug' => 'demo-kueche', 'email' => $second->email])->assertSuccessful();
        $this->artisan('merchant:grant', ['slug' => 'demo-kueche', 'email' => $third->email])->assertFailed();
        $this->artisan('merchant:grant', ['slug' => 'demo-kueche', 'email' => $second->email, '--role' => 'staff'])->assertSuccessful();
        $this->assertDatabaseMissing('restaurant_user', ['restaurant_id' => $this->r->id, 'user_id' => $third->id]);
        $this->artisan('billing:set-plan', ['slug' => 'demo-kueche', 'plan' => 'growth'])->assertSuccessful();
        $this->artisan('merchant:grant', ['slug' => 'demo-kueche', 'email' => $third->email])->assertSuccessful();
        $this->artisan('billing:set-plan', ['slug' => 'demo-kueche', 'plan' => 'starter'])->assertFailed();
        $this->assertSame('growth', $this->r->fresh()->billing_plan);
    }

    public function test_exports_are_plan_and_role_gated_and_escape_spreadsheet_formulas(): void
    {
        $url = '/api/v1/merchant/restaurants/demo-kueche/menu-export';
        $this->actingAs($this->owner)->get($url)->assertForbidden();
        $this->r->update(['billing_plan' => 'growth']);
        $this->r->menuItems()->first()->update(['name' => '=1+1']);
        $response = $this->get($url)->assertOk()->assertDownload('speisekarte.csv');
        $this->assertStringContainsString("'=1+1", $response->streamedContent());
        $this->owner->restaurants()->updateExistingPivot($this->r->id, ['role' => 'staff']);
        $this->get($url)->assertForbidden();
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
    }

    public function test_pro_activity_log_only_returns_own_restaurant_events(): void
    {
        $url = '/api/v1/merchant/restaurants/demo-kueche/activities';
        $this->actingAs($this->owner)->getJson($url)->assertForbidden();
        $this->r->update(['billing_plan' => 'pro']);
        $other = $this->r->replicate();
        $other->slug = 'other';
        $other->save();
        RestaurantActivity::create(['restaurant_id' => $other->id, 'action' => 'secret.other', 'metadata' => []]);
        $item = $this->r->menuItems()->first();
        $this->putJson('/api/v1/merchant/restaurants/demo-kueche/menu-items/'.$item->id, [...$item->only(['name', 'description', 'category', 'price_cents', 'allergens', 'sort_order']), 'is_available' => false])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.action', 'menu.updated')->assertJsonMissing(['action' => 'secret.other']);
        $this->owner->restaurants()->updateExistingPivot($this->r->id, ['role' => 'staff']);
        $this->getJson($url)->assertForbidden();
    }

    public function test_tariff_cannot_be_overwritten_through_general_settings(): void
    {
        $this->actingAs($this->owner)->patchJson('/api/v1/merchant/restaurants/demo-kueche', [
            'is_accepting_orders' => true, 'estimated_minutes' => 35, 'minimum_order_cents' => 1500, 'delivery_fee_cents' => 250, 'delivery_postcodes' => ['40721'], 'billing_plan' => 'pro',
        ])->assertOk();
        $this->assertSame('flex',$this->r->fresh()->billing_plan);
    }
}
