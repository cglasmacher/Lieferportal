<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MerchantPortalTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoCatalogSeeder::class);
        $this->restaurant = Restaurant::where('slug', 'demo-kueche')->firstOrFail();
        $this->owner = User::factory()->create(['email' => 'owner@example.test']);
        $this->owner->restaurants()->attach($this->restaurant->id, ['role' => 'owner']);
    }

    private function endpoint(string $path = ''): string
    {
        return '/api/v1/merchant/restaurants/demo-kueche'.$path;
    }

    private function menuPayload(): array
    {
        return ['name' => 'Neue Pizza', 'description' => 'Frische Zutaten', 'category' => 'Pizza', 'price_cents' => 1290, 'allergens' => ['Gluten', 'Milch'], 'sort_order' => 5, 'is_available' => true];
    }

    private function settings(): array
    {
        return ['is_accepting_orders' => false, 'estimated_minutes' => 55, 'minimum_order_cents' => 2000, 'delivery_fee_cents' => 300, 'delivery_postcodes' => ['40721']];
    }

    public function test_guests_cannot_read_or_write_merchant_data(): void
    {
        $this->getJson('/api/v1/merchant/session')->assertUnauthorized();
        $this->getJson($this->endpoint())->assertUnauthorized();
        $this->patchJson($this->endpoint(), $this->settings())->assertUnauthorized();
        $this->postJson($this->endpoint('/menu-items'), $this->menuPayload())->assertUnauthorized();
        $this->withoutVite()->get('/portal')->assertRedirect('/portal/login');
    }

    public function test_login_normalizes_email_rotates_session_and_logout_revokes_access(): void
    {
        $old = session()->getId();
        $this->postJson('/api/v1/merchant/login', ['email' => ' OWNER@example.test ', 'password' => 'password'])->assertOk();
        $this->assertAuthenticatedAs($this->owner);
        $this->assertNotSame($old, session()->getId());
        $this->getJson('/api/v1/merchant/session')->assertOk()->assertJsonCount(1, 'data.restaurants')->assertJsonPath('data.restaurants.0.role', 'owner');
        $this->postJson('/api/v1/merchant/logout')->assertOk();
        $this->assertGuest();
        $this->getJson($this->endpoint())->assertUnauthorized();
    }

    public function test_failed_logins_are_throttled_without_authenticating(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/merchant/login', ['email' => $this->owner->email, 'password' => 'incorrect'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/merchant/login', ['email' => $this->owner->email, 'password' => 'password'])->assertStatus(429)->assertHeader('Retry-After');
        $this->assertGuest();
    }

    public function test_malformed_credentials_return_validation_errors(): void
    {
        $this->postJson('/api/v1/merchant/login', ['email' => ['invalid'], 'password' => 'password'])->assertUnprocessable();
    }

    public function test_unrelated_users_cannot_access_restaurant_or_reassign_items(): void
    {
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->getJson($this->endpoint())->assertNotFound();
        $this->postJson($this->endpoint('/menu-items'), $this->menuPayload())->assertNotFound();
        $this->patchJson($this->endpoint(), $this->settings())->assertNotFound();
        $this->owner->restaurants()->detach();
        $this->actingAs($this->owner)->getJson($this->endpoint())->assertNotFound();
    }

    public function test_staff_can_read_but_cannot_edit_and_manager_can_edit(): void
    {
        $this->owner->restaurants()->updateExistingPivot($this->restaurant->id, ['role' => 'staff']);
        $this->actingAs($this->owner)->getJson($this->endpoint())->assertOk()->assertJsonPath('data.can_edit', false);
        $this->postJson($this->endpoint('/menu-items'), $this->menuPayload())->assertForbidden();
        $this->patchJson($this->endpoint(), $this->settings())->assertForbidden();
        $id = $this->restaurant->menuItems()->first()->id;
        $this->putJson($this->endpoint('/menu-items/'.$id), $this->menuPayload())->assertForbidden();
        $this->owner->restaurants()->updateExistingPivot($this->restaurant->id, ['role' => 'manager']);
        $this->patchJson($this->endpoint(), $this->settings())->assertOk();
    }

    public function test_owner_can_create_update_and_pause_menu_item(): void
    {
        $this->actingAs($this->owner);
        $payload = $this->menuPayload();
        $id = $this->postJson($this->endpoint('/menu-items'), $payload)->assertCreated()->json('data.id');
        $this->getJson('/api/v1/restaurants/demo-kueche')->assertJsonFragment(['name' => 'Neue Pizza']);
        $payload['is_available'] = false;
        $this->putJson($this->endpoint('/menu-items/'.$id), $payload)->assertOk();
        $this->getJson('/api/v1/restaurants/demo-kueche')->assertJsonMissing(['name' => 'Neue Pizza']);
        $this->assertDatabaseHas('menu_items', ['id' => $id, 'is_available' => false, 'restaurant_id' => $this->restaurant->id]);
    }

    public function test_foreign_item_ids_are_rejected_even_for_authorized_owner(): void
    {
        $other = $this->restaurant->replicate();
        $other->slug = 'other';
        $other->save();
        $item = $other->menuItems()->create($this->menuPayload());
        $this->actingAs($this->owner)->putJson($this->endpoint('/menu-items/'.$item->id), $this->menuPayload())->assertNotFound();
        $this->assertDatabaseHas('menu_items', ['id' => $item->id, 'restaurant_id' => $other->id]);
    }

    public function test_sensitive_fields_cannot_be_overwritten_or_read(): void
    {
        $this->actingAs($this->owner)->patchJson($this->endpoint(), [...$this->settings(), 'stripe_account_id' => 'acct_injected', 'paypal_merchant_id' => 'ABC1234567890', 'is_published' => false])->assertOk();
        $this->restaurant->refresh();
        $this->assertNull($this->restaurant->stripe_account_id);
        $this->assertNull($this->restaurant->paypal_merchant_id);
        $this->assertTrue($this->restaurant->is_published);
        $this->getJson($this->endpoint())->assertJsonMissingPath('data.stripe_account_id')->assertJsonMissingPath('data.paypal_merchant_id');
        $other = $this->restaurant->replicate();
        $other->slug = 'other';
        $other->save();
        $id = $this->postJson($this->endpoint('/menu-items'), [...$this->menuPayload(), 'restaurant_id' => $other->id])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('menu_items', ['id' => $id, 'restaurant_id' => $this->restaurant->id]);
    }

    public function test_invalid_prices_and_postcodes_are_rejected(): void
    {
        $this->actingAs($this->owner)->postJson($this->endpoint('/menu-items'), [...$this->menuPayload(), 'price_cents' => -100])->assertUnprocessable()->assertJsonValidationErrors('price_cents');
        $this->patchJson($this->endpoint(), [...$this->settings(), 'delivery_postcodes' => ['invalid']])->assertUnprocessable()->assertJsonValidationErrors('delivery_postcodes.0');
    }

    public function test_operator_can_grant_existing_user_access_without_resetting_password(): void
    {
        $user = User::factory()->create();
        $hash = $user->password;
        $this->artisan('merchant:grant', ['slug' => 'demo-kueche', 'email' => $user->email, '--role' => 'staff'])->assertSuccessful();
        $this->assertDatabaseHas('restaurant_user', ['user_id' => $user->id, 'restaurant_id' => $this->restaurant->id, 'role' => 'staff']);
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_operator_creates_only_unpublished_paused_restaurants(): void
    {
        $this->artisan('restaurant:create', ['slug' => 'new-business', 'name' => 'New Business'])->assertSuccessful();
        $this->assertDatabaseHas('restaurants', ['slug' => 'new-business', 'is_published' => false, 'is_accepting_orders' => false]);
        $this->getJson('/api/v1/restaurants/new-business')->assertNotFound();
        $this->artisan('merchant:grant', ['slug' => 'demo-kueche', 'email' => 'someone@example.test', '--role' => 'admin'])->assertFailed();
    }

    public function test_new_operator_account_password_is_hashed_and_mismatch_creates_nothing(): void
    {
        $this->artisan('merchant:grant', ['slug' => 'demo-kueche', 'email' => 'new-owner@example.test', '--name' => 'New Owner'])
            ->expectsQuestion('Neues Passwort (mindestens 12 Zeichen)', 'test-only-password-123')
            ->expectsQuestion('Passwort wiederholen', 'test-only-password-123')
            ->assertSuccessful();
        $user = User::where('email', 'new-owner@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('test-only-password-123', $user->password));
        $this->assertNotSame('test-only-password-123', $user->password);
        $this->assertDatabaseHas('restaurant_user', ['user_id' => $user->id, 'restaurant_id' => $this->restaurant->id, 'role' => 'owner']);
        $this->artisan('merchant:grant', ['slug' => 'demo-kueche', 'email' => 'mismatch@example.test', '--name' => 'Mismatch'])
            ->expectsQuestion('Neues Passwort (mindestens 12 Zeichen)', 'test-only-password-123')
            ->expectsQuestion('Passwort wiederholen', 'does-not-match-123')
            ->assertFailed();
        $this->assertDatabaseMissing('users', ['email' => 'mismatch@example.test']);
    }
}
