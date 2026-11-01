<?php

namespace Tests\Unit;

use App\Domain\Payments\PaymentDraft;
use App\Domain\Payments\PaypalMerchantOrder;
use App\Domain\Payments\StripeDirectCharge;
use PHPUnit\Framework\TestCase;

class PaymentSpecificationTest extends TestCase
{
    public function test_stripe_charge_is_scoped_to_restaurant_instead_of_platform(): void
    {
        $request = (new StripeDirectCharge)->build(new PaymentDraft('order-123', 3250, 179), 'acct_restaurant123');
        $this->assertSame('acct_restaurant123', $request['options']['stripe_account']);
        $this->assertSame('order-123', $request['options']['idempotency_key']);
        $this->assertSame(3250, $request['params']['amount']);
        $this->assertSame(179, $request['params']['application_fee_amount']);
        $this->assertArrayNotHasKey('transfer_data', $request['params']);
    }

    public function test_paypal_restaurant_receives_payment_and_fee_is_separate(): void
    {
        $request = (new PaypalMerchantOrder)->build(new PaymentDraft('order-123', 3250, 179), 'ABC1234567890');
        $unit = $request['body']['purchase_units'][0];
        $this->assertSame('ABC1234567890', $unit['payee']['merchant_id']);
        $this->assertSame('32.50', $unit['amount']['value']);
        $this->assertSame('1.79', $unit['payment_instruction']['platform_fees'][0]['amount']['value']);
    }

    public function test_missing_stripe_merchant_cannot_fall_back_to_platform_account(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new StripeDirectCharge)->build(new PaymentDraft('order-123', 3000, 150), '');
    }

    public function test_platform_fee_cannot_exceed_payment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PaymentDraft('order-123',100,100);
    }
}
