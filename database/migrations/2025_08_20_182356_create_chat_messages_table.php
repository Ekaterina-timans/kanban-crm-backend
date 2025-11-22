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
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained('chats')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // текст может быть пустым, если только вложения/опрос
            $table->text('content')->nullable();
            $table->enum('kind',['text','system','poll'])->default('text');
            $table->foreignId('reply_to_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            // например, распарсенные @mentions, ссылки и т.д.
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['chat_id','created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
