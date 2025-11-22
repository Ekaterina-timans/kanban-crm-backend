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
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->string('disk')->default('public');
            $table->string('path');                 // относительный путь на диске
            $table->string('original_name');        // имя у пользователя
            $table->string('mime', 191)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            // изображение (если применимо)
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            // для дедупликации (опц.): sha256 содержимого
            $table->string('sha256', 64)->nullable()->index();
            $table->unsignedBigInteger('uploaded_by')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['disk', 'path']);
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
