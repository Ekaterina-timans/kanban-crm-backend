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
        Schema::table('channel_threads', function (Blueprint $table) {
            $table->text('last_message_text')->nullable()->after('last_update_id');
            $table->timestamp('last_message_at')->nullable()->after('last_message_text');
            $table->unsignedBigInteger('last_message_external_id')->nullable()->after('last_message_at');

            $table->index(['group_channel_id', 'last_message_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channel_threads', function (Blueprint $table) {
            $table->dropIndex(['group_channel_id', 'last_message_at']);
            $table->dropColumn(['last_message_text', 'last_message_at', 'last_message_external_id']);
        });
    }
};
