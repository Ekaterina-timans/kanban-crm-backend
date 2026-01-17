<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('deadline_reminder_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('task_id')
                ->constrained()
                ->cascadeOnDelete();

            // key for de-duplication, e.g. "2026-01-11"
            $table->date('reminder_date');

            $table->unsignedTinyInteger('days_before');

            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'task_id', 'reminder_date'], 'uniq_deadline_reminder');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deadline_reminder_logs');
    }
};
