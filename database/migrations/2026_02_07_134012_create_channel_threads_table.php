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
        Schema::create('channel_threads', function (Blueprint $table) {
            $table->id();

            $table->foreignId('group_channel_id')
                ->constrained('group_channels')
                ->cascadeOnDelete();

            // Внешний идентификатор диалога у провайдера (tg chat_id, whatsapp thread id, email conversation id и т.д.)
            $table->string('external_chat_id', 64);

            // Внешний идентификатор собеседника/пира (tg user_id, телефон, email, page_id...) — опционально
            $table->string('external_peer_id', 64)->nullable();

            // Тип диалога у провайдера (например: private/group/channel)
            $table->string('thread_type', 32)->nullable();

            // Для отображения в UI
            $table->string('title', 150)->nullable();
            $table->string('username', 150)->nullable();
            $table->string('first_name', 150)->nullable();
            $table->string('last_name', 150)->nullable();

            // Для long polling/инкрементальной обработки (Telegram update_id, etc.)
            $table->unsignedBigInteger('last_update_id')->nullable();

            // Любые дополнительные данные провайдера
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(['group_channel_id', 'external_chat_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_threads');
    }
};
