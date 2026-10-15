<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->boolean('auto_approve')->default(false)->after('asaas_wallet_id'); // vendedor de confiança: publica sem fila de análise
        });
    }

    public function down(): void
    {
        Schema::table('seller_profiles', fn (Blueprint $table) => $table->dropColumn('auto_approve'));
    }
};
