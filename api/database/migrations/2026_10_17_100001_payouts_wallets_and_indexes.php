<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->string('asaas_wallet_id', 60)->nullable()->after('commission_bps'); // subconta do vendedor (split)
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('asaas_customer_id', 60)->nullable()->after('is_platform_admin');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('payout_status', 20)->default('pending')->after('seller_earning_cents'); // pending | split | paid_manual
            $table->timestamp('payout_paid_at')->nullable()->after('payout_status');
            $table->string('payout_note')->nullable()->after('payout_paid_at');

            $table->index(['seller_id', 'order_id']);
            $table->index(['seller_id', 'payout_status']);
            $table->index(['listing_id', 'license_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index(['status', 'paid_at']);
            $table->index(['status', 'expires_at']);
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->index(['category', 'status']);
        });

        Schema::table('listing_reports', function (Blueprint $table) {
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('listing_reports', fn (Blueprint $t) => $t->dropIndex(['status', 'created_at']));
        Schema::table('listings', fn (Blueprint $t) => $t->dropIndex(['category', 'status']));
        Schema::table('orders', function (Blueprint $t) {
            $t->dropIndex(['status', 'paid_at']);
            $t->dropIndex(['status', 'expires_at']);
        });
        Schema::table('order_items', function (Blueprint $t) {
            $t->dropIndex(['listing_id', 'license_id']);
            $t->dropIndex(['seller_id', 'payout_status']);
            $t->dropIndex(['seller_id', 'order_id']);
            $t->dropColumn(['payout_status', 'payout_paid_at', 'payout_note']);
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('asaas_customer_id'));
        Schema::table('seller_profiles', fn (Blueprint $t) => $t->dropColumn('asaas_wallet_id'));
    }
};
