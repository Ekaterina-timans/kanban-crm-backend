<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            // In-app notifications (bell)
            $table->boolean('inapp_chat_messages')->default(false);
            $table->boolean('inapp_mentions')->default(false);
            $table->boolean('inapp_task_assigned')->default(true);
            $table->boolean('inapp_comments_on_my_tasks')->default(false);
            $table->boolean('inapp_comments_on_assigned_tasks')->default(false);
            $table->boolean('inapp_deadline_reminders')->default(true);

            // Email notifications
            $table->boolean('email_chat_messages')->default(false);
            $table->boolean('email_task_assigned')->default(false);
            $table->boolean('email_comments_on_my_tasks')->default(false);
            $table->boolean('email_comments_on_assigned_tasks')->default(false);
            $table->boolean('email_deadline_reminders')->default(false);

            // Deadline reminder settings (локальное время в TZ пользователя из user_preferences.timezone)
            $table->unsignedTinyInteger('deadline_days_before')->default(1); // 0..255
            $table->string('deadline_notify_time', 5)->default('09:00'); // HH:mm

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
    }
};
