<?php

namespace App\Console\Commands;

use App\Domain\Billing\Referrals;
use App\Models\Restaurant;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class EnableReferrals extends Command
{
    protected $signature = 'referral:enable {slug} {company-key : Geprüfte, einheitliche Unternehmenskennung}';

    protected $description = 'Enable a referral code for a verified company';

    public function handle(Referrals $referrals): int
    {
        try {
            $key = $referrals->identity($this->argument('company-key'));
            $code = DB::transaction(function () use ($referrals, $key) {
                $restaurant = Restaurant::where('slug', $this->argument('slug'))->lockForUpdate()->first();
                if (! $restaurant) {
                    throw new DomainException('Restaurant nicht gefunden.');
                }
                if ($restaurant->referral_code) {
                    if ($restaurant->referral_company_key !== $key) {
                        throw new DomainException('Die geprüfte Unternehmenskennung ist bereits hinterlegt.');
                    }

                    return $restaurant->referral_code;
                }

                $code = $referrals->newCode();
                $restaurant->update(['referral_code' => $code, 'referral_company_key' => $key]);

                return $code;
            });
        } catch (DomainException|UniqueConstraintViolationException $exception) {
            $this->error($exception instanceof DomainException ? $exception->getMessage() : 'Für dieses Unternehmen besteht bereits ein Programmzugang.');

            return self::FAILURE;
        }

        $this->info('Empfehlungscode '.$code);

        return self::SUCCESS;
    }
}
