<?php

namespace App\Domain\Payments;

use InvalidArgumentException;

final readonly class PaymentDraft
{
    public function __construct(public string $id, public int $totalCents, public int $platformFeeCents)
    {
        if ($id === '' || strlen($id) > 36 || $totalCents < 1 || $platformFeeCents < 0 || $platformFeeCents >= $totalCents) {
            throw new InvalidArgumentException('Invalid payment draft.');
        }
    }

    public static function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
