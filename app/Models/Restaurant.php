<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Restaurant extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['stripe_account_id', 'paypal_merchant_id', 'referral_company_key', 'referral_code', 'referred_by_restaurant_id'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'is_accepting_orders' => 'boolean', 'delivery_postcodes' => 'array'];
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }
}
