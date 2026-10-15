<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 10); // image | video
            $table->string('original_name');
            $table->string('display_name');
            $table->string('disk', 30);
            $table->string('path', 500)->unique(); // caminho interno: nunca exposto pela API
            $table->string('mime_type', 100);
            $table->string('extension', 10);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum_sha256', 64)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 15)->default('processing'); // processing | ready | failed
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'type', 'created_at']);
            $table->index(['tenant_id', 'display_name']);
            $table->index(['tenant_id', 'checksum_sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
