<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('storage_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('driver', 10);
            $table->string('region', 80)->nullable();
            $table->string('endpoint')->nullable();
            $table->string('bucket', 100)->nullable();
            $table->string('prefix', 180)->default('');
            $table->string('public_url')->nullable();
            $table->boolean('path_style')->default(false);
            $table->text('credentials')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
        $localId = DB::table('storage_configurations')->insertGetId([
            'name' => '本地存储', 'driver' => 'local', 'prefix' => '', 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Schema::table('images', function (Blueprint $table) use ($localId) {
            $table->foreignId('storage_configuration_id')->default($localId)->constrained()->restrictOnDelete();
            $table->string('content_sha256', 64)->nullable();
            $table->dropUnique('images_fingerprint_unique');
            $table->unique(['storage_configuration_id', 'fingerprint'], 'images_storage_fingerprint_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('images')->where('storage_configuration_id', '!=', DB::table('storage_configurations')->where('driver', 'local')->value('id'))->exists()) {
            throw new RuntimeException('存在云端图片，不能移除存储归属结构。请恢复完整数据库备份。');
        }
        Schema::table('images', function (Blueprint $table) {
            $table->dropForeign(['storage_configuration_id']);
            $table->dropUnique('images_storage_fingerprint_unique');
            $table->unique('fingerprint');
            $table->dropColumn('storage_configuration_id');
            $table->dropColumn('content_sha256');
        });
        Schema::dropIfExists('storage_configurations');
    }
};
