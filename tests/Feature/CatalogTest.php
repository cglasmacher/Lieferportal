<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use Database\Seeders\DemoCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoCatalogSeeder::class);
    }

    private function restaurant(): Restaurant
    {
        return Restaurant::where('slug', 'demo-kueche')->firstOrFail();
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['fulfillment' => 'delivery', 'postcode' => '40721', 'items' => [
            ['menu_item_id' => $this->restaurant()->menuItems()->first()->id, 'quantity' => 2],
        ]], $overrides);
    }

    private function quote(array $payload)
    {
        return $this->postJson('/api/v1/restaurants/demo-kueche/quote', $payload);
    }

    public function test_catalog_never_exposes_merchant_identifiers(): void
    {
        $this->restaurant()->update(['stripe_account_id' => 'acct_secret123', 'paypal_merchant_id' => 'ABC1234567890']);
        $this->getJson('/api/v1/restaurants/demo-kueche')->assertOk()
            ->assertJsonMissingPath('data.stripe_account_id')->assertJsonMissingPath('data.paypal_merchant_id')
            ->assertJsonPath('data.payment_methods.0.available', false)->assertJsonPath('data.payment_methods.1.available', false);
    }

    public function test_unpublished_restaurant_is_hidden_from_all_public_routes(): void
    {
        $this->restaurant()->update(['is_published' => false]);
        $this->getJson('/api/v1/restaurants')->assertExactJson(['data' => []]);
        $this->getJson('/api/v1/restaurants/demo-kueche')->assertNotFound();
        $this->get('/r/demo-kueche')->assertNotFound();
        $this->quote($this->payload())->assertNotFound();
    }

    public function test_quote_recalculates_prices_and_cannot_start_payment(): void
    {
        $this->quote($this->payload(['total_cents' => 1, 'price_cents' => 1]))->assertOk()
            ->assertJsonPath('data.subtotal_cents', 2180)->assertJsonPath('data.delivery_fee_cents', 250)
            ->assertJsonPath('data.total_cents', 2430)->assertJsonPath('data.checkout_available', false);
    }

    public function test_delivery_must_match_restaurant_area(): void
    {
        $this->quote($this->payload(['postcode' => '10115']))->assertUnprocessable()->assertJsonValidationErrors('postcode');
    }

    public function test_delivery_minimum_does_not_apply_to_pickup(): void
    {
        $payload = $this->payload();
        $payload['items'][0]['quantity'] = 1;
        $this->quote($payload)->assertUnprocessable()->assertJsonValidationErrors('items');
        $payload['fulfillment'] = 'pickup';
        $payload['postcode'] = null;
        $this->quote($payload)->assertOk()->assertJsonPath('data.delivery_fee_cents', 0)->assertJsonPath('data.total_cents', 1090);
    }

    public function test_foreign_restaurant_item_is_rejected(): void
    {
        $other = Restaurant::create(['slug' => 'other', 'name' => 'Other', 'description' => 'Other', 'cuisine' => 'Other', 'address' => 'Other', 'city' => 'Hilden', 'delivery_postcodes' => []]);
        $foreign = $other->menuItems()->create(['category' => 'Other', 'name' => 'Other', 'description' => 'Other', 'price_cents' => 100, 'allergens' => []]);
        $this->quote($this->payload(['items' => [['menu_item_id' => $foreign->id, 'quantity' => 2]]]))->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_unavailable_item_and_paused_restaurant_are_rejected(): void
    {
        $payload = $this->payload();
        $this->restaurant()->menuItems()->update(['is_available' => false]);
        $this->quote($payload)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->restaurant()->update(['is_accepting_orders' => false]);
        $this->quote($payload)->assertUnprocessable()->assertJsonValidationErrors('restaurant');
    }

    public function test_empty_duplicate_and_negative_quantity_are_rejected(): void
    {
        $this->quote($this->payload(['items' => []]))->assertUnprocessable();
        $payload = $this->payload();
        $payload['items'][] = $payload['items'][0];
        $this->quote($payload)->assertUnprocessable();
        $payload = $this->payload();
        $payload['items'][0]['quantity'] = -1;
        $this->quote($payload)->assertUnprocessable();
    }

    public function test_demo_seed_is_repeatable_and_blocked_in_production(): void
    {
        $this->seed(DemoCatalogSeeder::class);
        $this->assertDatabaseCount('restaurants', 1);
        $this->assertDatabaseCount('menu_items', 4);
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(\RuntimeException::class);
        (new DemoCatalogSeeder)->run();
    }
}
