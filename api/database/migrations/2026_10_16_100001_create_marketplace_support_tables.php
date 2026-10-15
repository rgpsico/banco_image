<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('display_name', 80);
            $table->text('bio')->nullable();
            $table->string('status', 20)->default('active'); // active | suspended
            $table->unsignedSmallInteger('commission_bps')->nullable(); // sobrescreve a comissão padrão (2000 = 20%)
            $table->timestamp('terms_accepted_at');
            $table->timestamps();
        });

        Schema::create('listing_reports', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 30); // copyright | inappropriate | misleading | other
            $table->text('details')->nullable();
            $table->string('status', 20)->default('open'); // open | dismissed | actioned
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['listing_id', 'status']);
            $table->unique(['listing_id', 'reporter_id']); // uma denúncia por pessoa por publicação
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->text('tags_text')->nullable()->after('tags'); // tags em texto simples para a busca
        });
    }

    public function down(): void
    {
        Schema::table('listings', fn (Blueprint $table) => $table->dropColumn('tags_text'));
        Schema::dropIfExists('listing_reports');
        Schema::dropIfExists('seller_profiles');
    }
};
