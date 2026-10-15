<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category', 60)->nullable();
            $table->json('tags')->nullable();
            $table->string('status', 20)->default('draft'); // draft | pending_review | published | rejected | suspended | archived
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('rights_declared_at')->nullable(); // declaração de direitos autorais
            $table->boolean('has_people')->default(false);
            $table->boolean('model_release_confirmed')->default(false);
            $table->unsignedInteger('sales_count')->default(0);
            $table->unsignedInteger('views_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->index(['seller_id', 'status']);
            $table->unique('media_id'); // uma mídia = uma publicação
        });

        Schema::create('listing_licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('license_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('price_cents'); // sempre em centavos
            $table->char('currency', 3)->default('BRL');
            $table->timestamps();

            $table->unique(['listing_id', 'license_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_licenses');
        Schema::dropIfExists('listings');
    }
};
