<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('referral_code', 24)->nullable()->unique();
            $table->string('referral_company_key', 80)->nullable()->unique();
            $table->foreignId('referred_by_restaurant_id')->nullable()->constrained('restaurants')->restrictOnDelete();
        });

        Schema::table('monthly_billing_drafts', function (Blueprint $table) {
            $table->unsignedInteger('referral_discount_cents')->default(0);
            $table->unsignedInteger('qualified_referral_count')->default(0);
        });

        Schema::create('billing_payment_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('monthly_billing_draft_id')->constrained()->restrictOnDelete();
            $table->string('reference', 120)->unique();
            $table->unsignedInteger('amount_cents');
            $table->timestamp('confirmed_at');
            $table->timestamp('reversed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payment_confirmations');
        Schema::table('monthly_billing_drafts', function (Blueprint $table) {
            $table->dropColumn(['referral_discount_cents', 'qualified_referral_count']);
        });
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_restaurant_id');
            $table->dropUnique(['referral_code']);
            $table->dropUnique(['referral_company_key']);
            $table->dropColumn(['referral_code', 'referral_company_key']);
        });
    }
};
