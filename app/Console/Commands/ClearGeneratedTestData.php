<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearGeneratedTestData extends Command
{
    protected $signature = 'stats:clear-generated
        {--user=6 : ID пользователя}
        {--days=14 : За сколько дней удалять задачи}';

    protected $description = 'Удаляет тестовые задачи и связанные данные, созданные генератором статистики';

    public function handle()
    {
        $userId = (int) $this->option('user');
        $days   = (int) $this->option('days');

        $this->info("Удаляем задачи пользователя $userId за последние $days дней...");

        DB::transaction(function () use ($userId, $days) {

            // --- 1. Ищем задачи ---
            $tasks = Task::where('assignee_id', $userId)
                ->where('created_at', '>=', now()->subDays($days))
                ->get();

            if ($tasks->isEmpty()) {
                $this->warn("Нет задач для удаления.");
                return;
            }

            $taskIds = $tasks->pluck('id')->toArray();

            // --- 2. Ищем чеклисты ---
            $checklists = Checklist::whereIn('task_id', $taskIds)->get();
            $checklistIds = $checklists->pluck('id')->toArray();

            // --- 3. Ищем пункты ---
            $checklistItems = ChecklistItem::whereIn('checklist_id', $checklistIds)->get();
            $checklistItemIds = $checklistItems->pluck('id')->toArray();


            // --- 4. Удаляем логи для задач ---
            $deletedLogsTasks = ActivityLog::whereIn('entity_id', $taskIds)
                ->where('entity_type', 'task')
                ->delete();

            // --- 5. Удаляем логи чеклистов ---
            $deletedLogsChecklists = ActivityLog::whereIn('entity_id', $checklistIds)
                ->where('entity_type', 'checklist')
                ->delete();

            // --- 6. Удаляем логи пунктов чеклистов ---
            $deletedLogsItems = ActivityLog::whereIn('entity_id', $checklistItemIds)
                ->where('entity_type', 'checklist_item')
                ->delete();


            // --- 7. Удаляем пункты ---
            $deletedItems = ChecklistItem::whereIn('id', $checklistItemIds)->delete();

            // --- 8. Удаляем чеклисты ---
            $deletedChecklists = Checklist::whereIn('id', $checklistIds)->delete();

            // --- 9. Удаляем задачи ---
            $deletedTasks = Task::whereIn('id', $taskIds)->delete();


            $this->info("Удалено:");
            $this->info(" — Задач: $deletedTasks");
            $this->info(" — Чек-листов: $deletedChecklists");
            $this->info(" — Пунктов чек-листов: $deletedItems");
            $this->info(" — Логов задач: $deletedLogsTasks");
            $this->info(" — Логов чек-листов: $deletedLogsChecklists");
            $this->info(" — Логов пунктов: $deletedLogsItems");
        });

        $this->info("Очистка завершена!");
    }
}
