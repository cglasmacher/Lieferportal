<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateRestaurant extends Command
{
    protected $signature = 'restaurant:create {slug} {name}';

    protected $description = 'Create an unpublished, paused restaurant for operator-led onboarding.';

    public function handle(): int
    {
        $data = ['slug' => $this->argument('slug'), 'name' => $this->argument('name')];
        if (Validator::make($data, ['slug' => 'required|string|max:100|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/|unique:restaurants,slug', 'name' => 'required|string|max:120'])->fails()) {
            $this->error('Name oder Slug ungültig beziehungsweise bereits vergeben.');

            return self::FAILURE;
        }
        Restaurant::create([...$data, 'description' => '', 'cuisine' => '', 'address' => '', 'city' => '', 'delivery_postcodes' => []]);
        $this->info('Restaurant unveröffentlicht und pausiert angelegt.');

        return self::SUCCESS;
    }
}
