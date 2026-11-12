<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manuscripts', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('author_email');
            $table->string('original_filename');
            $table->string('file_path');
            $table->char('file_hash', 64)->index();
            $table->string('extracted_text_path')->nullable();
            $table->char('extracted_text_hash', 64)->nullable()->index();
            $table->string('priority', 20)->default('normal')->index();
            $table->string('status', 30)->default('received')->index();
            $table->unsignedInteger('status_version')->default(0);
            $table->boolean('needs_attention')->default(false)->index();
            $table->unsignedTinyInteger('similarity_score')->nullable();
            $table->unsignedInteger('word_count')->nullable();
            $table->text('preview')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manuscripts');
    }
};
