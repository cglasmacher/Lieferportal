<?php

namespace App\Console\Commands;

use App\Domain\Billing\Referrals;
use App\Models\Restaurant;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;

class CreateRestaurant extends Command
{
    protected $signature = 'restaurant:create {slug} {name} {--referral-code=} {--company-key=}';

    protected $description = 'Create an unpublished, paused restaurant for operator-led onboarding.';

    public function handle(Referrals $referrals): int
    {
        $data = ['slug' => $this->argument('slug'), 'name' => $this->argument('name')];
        if (Validator::make($data, ['slug' => 'required|string|max:100|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/|unique:restaurants,slug', 'name' => 'required|string|max:120'])->fails()) {
            $this->error('Name oder Slug ungültig beziehungsweise bereits vergeben.');

            return self::FAILURE;
        }
        try {
            $key = $this->option('company-key');
            $code = $this->option('referral-code');
            if ($code !== null && $key === null) {
                throw new DomainException('Bei Empfehlungen muss die Verwaltung die Unternehmenskennung des Neukunden prüfen.');
            }
            if ($key !== null) {
                $key = $referrals->identity($key);
                $data['referral_company_key'] = $key;
                $data['referral_code'] = $referrals->newCode();
            }
            if ($code !== null) {
                $referrer = Restaurant::where('referral_code', strtoupper(trim($code)))->first();
                if (! $referrer || $referrer->referral_company_key === $key) {
                    throw new DomainException('Empfehlungscode ungültig oder beide Standorte gehören demselben Unternehmen.');
                }
                $data['referred_by_restaurant_id'] = $referrer->id;
            }

            Restaurant::create([...$data, 'description' => '', 'cuisine' => '', 'address' => '', 'city' => '', 'delivery_postcodes' => []]);
        } catch (DomainException|UniqueConstraintViolationException $exception) {
            $this->error($exception instanceof DomainException ? $exception->getMessage() : 'Restaurant oder Unternehmen bereits vorhanden.');

            return self::FAILURE;
        }
        $this->info('Restaurant unveröffentlicht und pausiert angelegt.');

        return self::SUCCESS;
    }
}
