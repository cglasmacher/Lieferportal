<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MonthlyBillingDraft extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['period_month' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d', 'tariff_snapshot' => 'array', 'recipient_snapshot' => 'array', 'net_cents' => 'integer', 'vat_cents' => 'integer', 'gross_cents' => 'integer', 'referral_discount_cents' => 'integer', 'qualified_referral_count' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException('Ein gespeicherter Monatsentwurf wird nicht überschrieben.'));
        static::deleting(fn () => throw new DomainException('Ein gespeicherter Monatsentwurf wird nicht gelöscht.'));
    }
}
