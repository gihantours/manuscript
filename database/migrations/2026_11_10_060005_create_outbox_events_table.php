<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 60)->index();
            $table->string('aggregate_id', 64)->index();
            $table->json('payload');
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['published_at', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
