<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->unique();
            $t->timestamps();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->json('value')->nullable();
            $t->timestamps();
        });
        Schema::create('images', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->nullable()->constrained('groups')->nullOnDelete();
            $t->string('original_name');
            $t->string('display_name');
            $t->string('alt', 500)->nullable();
            $t->string('path')->unique();
            $t->string('preview_path');
            $t->string('original_path')->nullable();
            $t->string('mime', 50);
            foreach (['source_width', 'source_height', 'width', 'height'] as $n) {
                $t->unsignedInteger($n);
            }
            foreach (['source_bytes', 'bytes', 'original_bytes'] as $n) {
                $t->unsignedBigInteger($n)->default(0);
            }
            $t->string('source_hash', 64)->index();
            $t->string('fingerprint', 64)->nullable()->unique();
            $t->json('processing');
            $t->timestamp('disabled_at')->nullable();
            $t->softDeletes();
            $t->timestamps();
            $t->index(['created_at', 'group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('images');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('groups');
    }
};
