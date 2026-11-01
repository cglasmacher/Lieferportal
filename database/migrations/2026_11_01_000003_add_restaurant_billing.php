<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $t) {
            $t->enum('billing_plan', ['flex', 'starter', 'growth', 'pro'])->default('flex');
            $t->enum('requested_billing_plan', ['flex', 'starter', 'growth', 'pro'])->nullable();
            $t->timestamp('billing_requested_at')->nullable();
            $t->foreignId('billing_requested_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::create('restaurant_activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action');
            $t->json('metadata');
            $t->timestamps();
            $t->index(['restaurant_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_activities');
        Schema::table('restaurants', function (Blueprint $t) {
            $t->dropConstrainedForeignId('billing_requested_by');
            $t->dropColumn(['billing_plan', 'requested_billing_plan', 'billing_requested_at']);
        });
    }
};
