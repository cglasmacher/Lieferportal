<?php

namespace App\Domain\Pricing;

use InvalidArgumentException;

final class Commission
{
    /** All inputs/outputs are integer EUR cents. Half-up rounding per order. */
    public function calculate(int $merchandiseCents, int $rate = 500, int $vatRate = 1900): array
    {
        if ($merchandiseCents < 0 || $rate < 0 || $rate > 10000 || $vatRate < 0 || $vatRate > 10000) {
            throw new InvalidArgumentException('Invalid commission inputs.');
        }
        $net = intdiv($merchandiseCents * $rate + 5000, 10000);
        $vat = intdiv($net * $vatRate + 5000, 10000);

        return ['net_cents' => $net, 'vat_cents' => $vat, 'gross_cents' => $net + $vat];
    }
}
