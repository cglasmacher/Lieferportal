<?php

namespace App\Console\Commands;

use App\Domain\Billing\MonthlyBilling;
use App\Models\Restaurant;
use DomainException;
use Illuminate\Console\Command;
use InvalidArgumentException;

class PrepareMonthlyBilling extends Command
{
    protected $signature = 'billing:prepare {slug} {month : JJJJ-MM}';

    protected $description = 'Create or retrieve one immutable monthly flat-fee draft. Does not invoice or collect money.';

    public function handle(MonthlyBilling $billing): int
    {
        $r = Restaurant::where('slug', $this->argument('slug'))->first();
        if (! $r) {
            $this->error('Restaurant nicht gefunden.');

            return self::FAILURE;
        }
        try {
            $draft = $billing->prepare($r, $this->argument('month'));
        } catch (DomainException|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Entwurf '.$draft->id.' · '.$draft->period_month->format('Y-m').' · '.number_format($draft->gross_cents / 100, 2, ',', '.').' EUR brutto. Keine Rechnung, kein Einzug.');

        return self::SUCCESS;
    }
}
