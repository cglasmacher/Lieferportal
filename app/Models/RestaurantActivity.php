<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantActivity extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
