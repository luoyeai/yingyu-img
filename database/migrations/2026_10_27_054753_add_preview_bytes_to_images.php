<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('images', fn (Blueprint $table) => $table->unsignedBigInteger('preview_bytes')->default(0));
    }

    public function down(): void
    {
        Schema::table('images', fn (Blueprint $table) => $table->dropColumn('preview_bytes'));
    }
};
