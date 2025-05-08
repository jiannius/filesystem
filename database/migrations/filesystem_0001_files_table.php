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
        if (Schema::hasTable('files')) return;

        Schema::create('files', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('mime')->nullable();
            $table->string('extension')->nullable();
            $table->decimal('kb', 20, 2)->nullable();
            $table->string('disk')->nullable();
            $table->text('path')->nullable();
            $table->text('url')->nullable();
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->string('alt')->nullable();
            $table->string('visibility')->nullable();
            $table->string('env')->nullable();
            $table->json('data')->nullable();
            $table->boolean('is_resized')->nullable();
            $table->boolean('is_converted_to_webp')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
