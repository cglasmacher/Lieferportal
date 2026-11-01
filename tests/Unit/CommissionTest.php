<?php

namespace Tests\Unit;

use App\Domain\Pricing\Commission;
use PHPUnit\Framework\TestCase;

class CommissionTest extends TestCase
{
    public function test_commission_uses_merchandise_cents_and_separate_vat(): void
    {
        $this->assertSame(['net_cents' => 150, 'vat_cents' => 29, 'gross_cents' => 179], (new Commission)->calculate(3000));
    }

    public function test_half_cent_rounds_up_and_zero_is_supported(): void
    {
        $this->assertSame(1, (new Commission)->calculate(10)['net_cents']);
        $this->assertSame(['net_cents' => 0, 'vat_cents' => 0, 'gross_cents' => 0], (new Commission)->calculate(0));
    }

    public function test_negative_merchandise_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Commission)->calculate(-1);
    }
}
