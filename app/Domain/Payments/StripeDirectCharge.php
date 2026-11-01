<?php

namespace App\Domain\Payments;

use InvalidArgumentException;

/** Request specification only. Does not call Stripe or accept payments. */
final class StripeDirectCharge
{
    public function build(PaymentDraft $draft, string $accountId): array
    {
        if (! preg_match('/^acct_[a-zA-Z0-9]+$/', $accountId)) {
            throw new InvalidArgumentException('A connected restaurant account is required.');
        }

        return [
            'options' => ['stripe_account' => $accountId, 'idempotency_key' => $draft->id],
            'params' => [
                'amount' => $draft->totalCents, 'currency' => 'eur',
                'application_fee_amount' => $draft->platformFeeCents,
                'payment_method_types' => ['card'],
                'metadata' => ['order_id' => $draft->id],
            ],
        ];
    }
}
