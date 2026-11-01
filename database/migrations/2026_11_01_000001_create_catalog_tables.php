<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurants', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('description');
            $table->string('cuisine');
            $table->string('address');
            $table->string('city');
            $table->boolean('is_published')->default(false);
            $table->boolean('is_accepting_orders')->default(false);
            $table->unsignedInteger('minimum_order_cents')->default(0);
            $table->unsignedInteger('delivery_fee_cents')->default(0);
            $table->unsignedSmallInteger('estimated_minutes')->default(45);
            $table->json('delivery_postcodes');
            $table->string('stripe_account_id')->nullable()->unique();
            $table->string('paypal_merchant_id')->nullable()->unique();
            $table->timestamps();
        });
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->string('name');
            $table->text('description');
            $table->unsignedInteger('price_cents');
            $table->json('allergens');
            $table->boolean('is_available')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['restaurant_id', 'is_available', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('restaurants');
    }
};
