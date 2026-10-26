<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Data em que a foto foi tirada (EXIF) ou, na falta dela, a do envio: é o filtro da galeria.
        Schema::table('media', function (Blueprint $table) {
            $table->timestamp('taken_at')->nullable()->after('status');
            $table->index(['tenant_id', 'taken_at']);
        });
        DB::table('media')->whereNull('taken_at')->update(['taken_at' => DB::raw('created_at')]);

        Schema::create('galleries', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collection_id')->nullable()->constrained('collections')->nullOnDelete(); // vazio = todas as fotos do projeto
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 120);
            $table->string('description', 500)->nullable();
            $table->string('token', 48)->unique();           // o link público: longo e aleatório
            $table->boolean('is_active')->default(true);
            $table->boolean('allow_download')->default(false); // baixar o original: desligado por padrão
            $table->boolean('watermark')->default(true);       // prévias com marca d'água: ligado por padrão
            $table->unsignedInteger('views_count')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'is_active']);
        });

        // Chave de API: "read" (padrão) ou "read,write" (pode enviar fotos).
        Schema::table('tenant_api_keys', function (Blueprint $table) {
            $table->string('scopes', 40)->default('read')->after('key_hash');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_api_keys', fn (Blueprint $t) => $t->dropColumn('scopes'));
        Schema::dropIfExists('galleries');
        Schema::table('media', function (Blueprint $t) {
            $t->dropIndex(['tenant_id', 'taken_at']);
            $t->dropColumn('taken_at');
        });
    }
};
