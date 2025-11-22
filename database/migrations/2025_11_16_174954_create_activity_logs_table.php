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
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            // К какой группе относится действие
            $table->unsignedBigInteger('group_id');

            // Кто сделал действие
            $table->unsignedBigInteger('user_id');

            // Тип сущности (например: space, task, column, participant)
            $table->string('entity_type');

            // ID сущности, над которой произошло действие
            $table->unsignedBigInteger('entity_id');

            // Тип действия (created / updated / deleted)
            $table->string('action');

            // Храним изменения — old / new значения
            $table->json('changes')->nullable();

            $table->timestamps();

            // FK + индексы
            $table->index(['group_id', 'entity_type', 'entity_id']);
            $table->index('user_id');
            $table->index('action');

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->onDelete('cascade');

            $table->foreign('group_id')
                ->references('id')->on('groups')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
