<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('similarity_caches', function (Blueprint $table) {
            $table->char('content_hash', 64)->primary();
            $table->unsignedTinyInteger('score');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('similarity_caches');
    }
};
