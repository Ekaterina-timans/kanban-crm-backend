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
        Schema::table('tasks', function (Blueprint $table) {
            // Удаляем старый enum статус
            if (Schema::hasColumn('tasks', 'status')) {
                $table->dropColumn('status');
            }

            // Убедимся, что колонки status_id и priority_id существуют
            if (!Schema::hasColumn('tasks', 'status_id')) {
                $table->unsignedBigInteger('status_id')->after('assignee_id');
            }

            if (!Schema::hasColumn('tasks', 'priority_id')) {
                $table->unsignedBigInteger('priority_id')->after('status_id');
            }

            // Устанавливаем обязательные значения
            $table->unsignedBigInteger('status_id')->change();
            $table->unsignedBigInteger('priority_id')->change();
        });

        // Добавляем внешние ключи
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreign('status_id')->references('id')->on('statuses')->onDelete('cascade');
            $table->foreign('priority_id')->references('id')->on('priorities')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Удаляем внешние ключи
            $table->dropForeign(['status_id']);
            $table->dropForeign(['priority_id']);

            // Удаляем новые колонки
            $table->dropColumn('status_id');
            $table->dropColumn('priority_id');

            // Восстанавливаем старый enum статус
            $table->enum('status', ['created', 'in_progress', 'completed'])->after('assignee_id');
        });
    }
};
