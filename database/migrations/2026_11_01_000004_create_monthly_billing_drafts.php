<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_billing_terms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $t->date('effective_month');
            $t->string('plan_key');
            $t->string('plan_name');
            $t->unsignedInteger('monthly_net_cents');
            $t->unsignedSmallInteger('vat_basis_points');
            $t->unsignedSmallInteger('commission_basis_points');
            $t->timestamps();
            $t->unique(['restaurant_id', 'effective_month']);
        });
        Schema::create('monthly_billing_drafts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $t->foreignId('restaurant_billing_term_id')->constrained()->restrictOnDelete();
            $t->date('period_month');
            $t->date('period_end');
            $t->string('currency', 3)->default('EUR');
            $t->json('tariff_snapshot');
            $t->json('recipient_snapshot');
            $t->unsignedInteger('net_cents');
            $t->unsignedInteger('vat_cents');
            $t->unsignedInteger('gross_cents');
            $t->timestamps();
            $t->unique(['restaurant_id', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_billing_drafts');
        Schema::dropIfExists('restaurant_billing_terms');
    }
};
