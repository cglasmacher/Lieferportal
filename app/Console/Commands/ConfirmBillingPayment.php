<?php

namespace App\Console\Commands;

use App\Models\MonthlyBillingDraft;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ConfirmBillingPayment extends Command
{
    protected $signature = 'billing:confirm-payment {draft} {reference} {amount-cents} {--reverse : Bestätigung wegen Rückzahlung oder Rückbuchung aufheben}';

    protected $description = 'Record a reconciled external monthly payment or reverse its confirmation';

    public function handle(): int
    {
        $reference = trim($this->argument('reference'));
        $amount = filter_var($this->argument('amount-cents'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($reference === '' || mb_strlen($reference) > 120 || $amount === false) {
            $this->error('Eine Zahlungsreferenz und ein positiver Centbetrag sind erforderlich.');

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($reference, $amount) {
                $draft = MonthlyBillingDraft::whereKey($this->argument('draft'))->lockForUpdate()->first();
                if (! $draft || $draft->gross_cents !== $amount) {
                    throw new DomainException('Entwurf fehlt oder der Betrag stimmt nicht mit dem Bruttobetrag überein.');
                }

                $payments = DB::table('billing_payment_confirmations');
                $existing = (clone $payments)->where('reference', $reference)->first();
                if ($existing && $existing->monthly_billing_draft_id !== $draft->id) {
                    throw new DomainException('Die Zahlungsreferenz gehört bereits zu einem anderen Entwurf.');
                }
                if ($this->option('reverse')) {
                    if (! $existing) {
                        throw new DomainException('Keine passende Zahlungsbestätigung gefunden.');
                    }

                    $payments->where('id', $existing->id)->whereNull('reversed_at')->update(['reversed_at' => now()]);

                    return;
                }
                if ($existing) {
                    if ($existing->reversed_at !== null) {
                        throw new DomainException('Diese Referenz wurde zurückgebucht. Für eine neue Zahlung ist eine neue Referenz erforderlich.');
                    }

                    return;
                }
                if ((clone $payments)->where('monthly_billing_draft_id', $draft->id)->whereNull('reversed_at')->exists()) {
                    throw new DomainException('Für diesen Monat besteht bereits eine aktive Zahlungsbestätigung.');
                }

                $payments->insert(['monthly_billing_draft_id' => $draft->id, 'reference' => $reference, 'amount_cents' => $amount, 'confirmed_at' => now()]);
            });
        } catch (DomainException|UniqueConstraintViolationException $exception) {
            $this->error($exception instanceof DomainException ? $exception->getMessage() : 'Diese Zahlungsreferenz ist bereits vergeben.');

            return self::FAILURE;
        }

        $this->info($this->option('reverse') ? 'Bestätigung aufgehoben.' : 'Externer Zahlungseingang bestätigt.');

        return self::SUCCESS;
    }
}
