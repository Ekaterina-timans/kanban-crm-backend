<?php

namespace App\Console\Commands;

use App\Models\DeadlineReminderLog;
use App\Models\NotificationSetting;
use App\Models\Task;
use App\Models\User;
use App\Notifications\DeadlineReminderEmailNotification;
use App\Notifications\DeadlineReminderInAppNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;

// Команда (cron job) для рассылки дедлайнов
class SendDeadlineReminders extends Command
{
    protected $signature = 'notifications:deadline-reminders';
    protected $description = 'Send deadline reminders to users based on notification settings';

    public function handle(): int
    {
        $settingsList = NotificationSetting::query()
            ->where(function ($q) {
                $q->where('inapp_deadline_reminders', true)
                  ->orWhere('email_deadline_reminders', true);
            })
            ->get();

        $sentCount = 0;

        foreach ($settingsList as $settings) {
            $user = User::with('preference')->find($settings->user_id);
            if (!$user) continue;

            // TZ из user_preferences.timezone
            $tz = $user->preference?->timezone ?? config('app.timezone');
            $now = Carbon::now($tz);

            // время отправки
            $notifyTime = $settings->deadline_notify_time ?: '09:00';
            [$hh, $mm] = array_pad(explode(':', $notifyTime), 2, '00');

            $shouldSendNow =
                (int)$now->format('H') > (int)$hh ||
                ((int)$now->format('H') === (int)$hh && (int)$now->format('i') >= (int)$mm);

            if (!$shouldSendNow) {
                continue;
            }

            $daysBefore = (int)($settings->deadline_days_before ?? 1);

            // due_date = DATETIME, поэтому ищем задачи по границам дня в TZ пользователя
            $targetStartLocal = $now->copy()->addDays($daysBefore)->startOfDay(); // 00:00:00 в TZ пользователя
            $targetEndLocal   = $targetStartLocal->copy()->endOfDay();           // 23:59:59 в TZ пользователя

            // Переводим границы в UTC (обычно due_date в БД хранят в UTC; даже если нет — это самый стабильный подход)
            $targetStartUtc = $targetStartLocal->copy()->utc();
            $targetEndUtc   = $targetEndLocal->copy()->utc();

            $tasks = Task::query()
                ->whereNotNull('due_date')
                ->whereBetween('due_date', [$targetStartUtc, $targetEndUtc])
                ->where('assignee_id', $user->id)
                ->get();

            foreach ($tasks as $task) {

                // дедуп по дате "сегодня" в TZ пользователя
                $alreadySentToday = DeadlineReminderLog::query()
                    ->where('user_id', $user->id)
                    ->where('task_id', $task->id)
                    ->where('reminder_date', $now->toDateString())
                    ->exists();

                if ($alreadySentToday) continue;

                // если оба канала вдруг выключены — ничего не делаем и НЕ логируем
                if (!$settings->inapp_deadline_reminders && !$settings->email_deadline_reminders) {
                    continue;
                }

                // создаём лог ДО отправки (для дедуп), но sent_at ставим после
                $log = DeadlineReminderLog::create([
                    'user_id' => $user->id,
                    'task_id' => $task->id,
                    'reminder_date' => $now->toDateString(),
                    'days_before' => $daysBefore,
                    'sent_at' => null,
                ]);

                // отправляем только те каналы, которые включены
                if ($settings->inapp_deadline_reminders) {
                    $user->notify(new DeadlineReminderInAppNotification($task, $daysBefore));
                }

                if ($settings->email_deadline_reminders) {
                    $user->notify(new DeadlineReminderEmailNotification($task, $daysBefore));
                }

                $log->update(['sent_at' => now()->utc()]);

                $sentCount++;
            }
        }

        $this->info("Deadline reminders sent: {$sentCount}");
        return Command::SUCCESS;
    }
}
