<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manuscript_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 60);
            $table->string('recipient');
            $table->string('subject');
            $table->string('idempotency_key', 191)->unique();
            $table->unsignedInteger('status_version')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['manuscript_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_deliveries');
    }
};
