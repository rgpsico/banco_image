<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Palavras-chave para a busca (ex.: "hamburguer,burger,lanche"). Minúsculas, separadas por vírgula. */
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->text('tags')->nullable()->after('display_name');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn('tags');
        });
    }
};
