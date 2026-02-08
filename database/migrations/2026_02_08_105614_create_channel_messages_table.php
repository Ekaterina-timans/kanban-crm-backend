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
        Schema::create('channel_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('channel_thread_id')
                ->constrained('channel_threads')
                ->cascadeOnDelete();

            // in = входящее от клиента, out = исходящее от нас
            $table->string('direction', 8); // in|out

            // Внешние ID (Telegram message_id, WhatsApp message id, etc.)
            $table->string('external_message_id', 64)->nullable();

            // Для Telegram можно хранить update_id (удобно для отладки/инкремента)
            $table->unsignedBigInteger('external_update_id')->nullable();

            // Кто отправил у провайдера (tg user id / phone / email и т.д.)
            $table->string('sender_external_id', 64)->nullable();

            // Текстовое содержимое (если есть)
            $table->text('text')->nullable();

            // Сырые данные сообщения (весь msg/update) — пригодится для вложений и расширений
            $table->json('payload')->nullable();

            // Время сообщения у провайдера (tg date)
            $table->unsignedBigInteger('provider_date')->nullable();

            $table->timestamps();

            $table->index(['channel_thread_id', 'created_at']);
            $table->index(['channel_thread_id', 'direction']);

            // Защита от дублей (один и тот же external_message_id не должен повторяться в рамках треда и направления)
            $table->unique(['channel_thread_id', 'external_message_id', 'direction'], 'uniq_thread_extmsg_dir');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_messages');
    }
};
