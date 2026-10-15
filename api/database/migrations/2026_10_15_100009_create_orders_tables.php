<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('pending'); // pending | paid | cancelled | refunded | expired
            $table->unsignedBigInteger('subtotal_cents')->default(0);
            $table->unsignedBigInteger('platform_fee_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->char('currency', 3)->default('BRL');
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['buyer_id', 'status']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained()->restrictOnDelete();
            $table->foreignId('license_id')->constrained()->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->string('title_snapshot');
            $table->unsignedBigInteger('unit_price_cents');
            $table->unsignedSmallInteger('commission_bps'); // comissão em pontos-base (2000 = 20%) congelada na compra
            $table->unsignedBigInteger('platform_fee_cents');
            $table->unsignedBigInteger('seller_earning_cents');
            $table->timestamps();

            $table->index('seller_id');
            $table->index('listing_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
