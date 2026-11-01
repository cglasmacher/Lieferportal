<?php

namespace App\Domain\Payments;

use InvalidArgumentException;

/** Request specification only. Partner approval and seller onboarding are prerequisites. */
final class PaypalMerchantOrder
{
    public function build(PaymentDraft $draft, string $merchantId): array
    {
        if (! preg_match('/^[A-Z0-9]{13}$/', $merchantId)) {
            throw new InvalidArgumentException('A restaurant PayPal merchant ID is required.');
        }

        return [
            'request_id' => $draft->id,
            'body' => [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $draft->id,
                    'custom_id' => $draft->id,
                    'payee' => ['merchant_id' => $merchantId],
                    'amount' => ['currency_code' => 'EUR', 'value' => PaymentDraft::decimal($draft->totalCents)],
                    'payment_instruction' => [
                        'disbursement_mode' => 'INSTANT',
                        'platform_fees' => [['amount' => ['currency_code' => 'EUR', 'value' => PaymentDraft::decimal($draft->platformFeeCents)]]],
                    ],
                ]],
            ],
        ];
    }
}
