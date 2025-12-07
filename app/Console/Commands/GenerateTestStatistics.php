<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Column;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;

class GenerateTestStatistics extends Command
{
    protected $signature = 'stats:generate
        {--user=6 : ID пользователя}
        {--group=3 : ID группы}
        {--space=5 : ID пространства}
        {--count=30 : Количество задач}
        {--days=14 : За сколько дней распределить}';

    protected $description = 'Генерирует задачи, чеклисты и activity_logs для тестирования статистики';

    public function handle()
    {
        $userId  = (int)$this->option('user');
        $groupId = (int)$this->option('group');
        $spaceId = (int)$this->option('space');
        $count   = (int)$this->option('count');
        $days    = (int)$this->option('days');

        $this->info("Генерация $count задач за $days дней...");

        // Все колонки пространства
        $columns = Column::where('space_id', $spaceId)
            ->pluck('id')
            ->unique()
            ->values()
            ->toArray();

        if (empty($columns)) {
            $this->error("Нет колонок в пространстве ID=$spaceId");
            return;
        }

        $this->info("Используем колонки: " . implode(', ', $columns));

        DB::transaction(function () use ($columns, $count, $days, $userId, $groupId) {

            for ($i = 0; $i < $count; $i++) {

                // дата создания задачи
                $createdAt = Carbon::now()
                    ->subDays(rand(0, $days - 1))
                    ->setHour(rand(8, 20))
                    ->setMinute(rand(0, 59))
                    ->setSecond(rand(0, 59));

                // случайная колонка
                $columnId = Arr::random($columns);

                // создаём задачу
                $task = new Task();
                $task->timestamps = false; // ВАЖНО!
                $task->column_id   = $columnId;
                $task->name        = "Тестовая задача #" . ($i + 1);
                $task->description = "Генерация для статистики";
                $task->assignee_id = $userId;
                $task->author_id   = $userId;
                $task->priority_id = rand(1, 3);
                $task->status_id   = rand(1, 3);
                $task->due_date    = rand(0, 1) ? (clone $createdAt)->addDays(rand(1, 5)) : null;
                $task->created_at  = $createdAt;
                $task->updated_at  = $createdAt;
                $task->save();

                // чек-лист
                $checklist = new Checklist();
                $checklist->timestamps = false; // фикс
                $checklist->task_id = $task->id;
                $checklist->title = "Чек-лист задачи";
                $checklist->created_at = $createdAt;
                $checklist->updated_at = $createdAt;
                $checklist->save();

                // пункты чек-листа
                for ($j = 0; $j < rand(2, 5); $j++) {

                    $itemCreated = (clone $createdAt)->addMinutes(rand(10, 200));

                    $item = new ChecklistItem();
                    $item->timestamps = false; // фикс
                    $item->checklist_id = $checklist->id;
                    $item->name = "Пункт " . ($j + 1);
                    $item->assignee_id = rand(0, 1) ? $userId : null;
                    $item->completed = rand(0, 1);
                    $item->due_date = rand(0, 1)
                        ? (clone $createdAt)->addDays(rand(1, 3))
                        : null;
                    $item->created_at = $itemCreated;
                    $item->updated_at = $itemCreated;
                    $item->save();
                }

                // лог создания задачи
                ActivityLog::insert([
                    'group_id'    => $groupId,
                    'user_id'     => $userId,
                    'entity_type' => 'task',
                    'entity_id'   => $task->id,
                    'action'      => 'created',
                    'changes'     => null,
                    'created_at'  => $createdAt,
                    'updated_at'  => $createdAt,
                ]);

                // лог завершения
                if ($task->status_id === 3) {

                    $doneAt = (clone $createdAt)
                        ->addDays(rand(0, 5))
                        ->addHours(rand(1, 10))
                        ->addMinutes(rand(0, 59));

                    ActivityLog::insert([
                        'group_id'    => $groupId,
                        'user_id'     => $userId,
                        'entity_type' => 'task',
                        'entity_id'   => $task->id,
                        'action'      => 'status_updated',
                        'changes'     => json_encode([
                            'old' => rand(1, 2),
                            'new' => 3
                        ]),
                        'created_at'  => $doneAt,
                        'updated_at'  => $doneAt,
                    ]);
                }
            }
        });

        $this->info("Генерация завершена!");
    }
}
