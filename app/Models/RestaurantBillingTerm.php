<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

class RestaurantBillingTerm extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_month' => 'date:Y-m-d', 'monthly_net_cents' => 'integer', 'vat_basis_points' => 'integer', 'commission_basis_points' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException('Gespeicherte Abrechnungsbedingungen werden nicht überschrieben.'));
        static::deleting(fn () => throw new DomainException('Gespeicherte Abrechnungsbedingungen werden nicht gelöscht.'));
    }
}
