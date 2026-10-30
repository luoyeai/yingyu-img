<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('storage_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storage_configuration_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('image_id')->nullable()->unique();
            $table->string('operation', 30);
            $table->string('path');
            $table->string('identity', 64)->nullable();
            $table->json('payload');
            $table->string('state', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->string('error_code', 40)->nullable();
            $table->timestamps();
            $table->unique(['storage_configuration_id', 'identity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('storage_operations');
    }
};
