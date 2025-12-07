<?php

namespace App\Services;

use App\Models\Task;
use App\Models\ChecklistItem;
use App\Models\ActivityLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class UserStatisticsService
{
    const STATUS_CREATED = 1;
    const STATUS_IN_PROGRESS = 2;
    const STATUS_DONE = 3;

    /**
     * Главная точка входа: собираем все виджеты.
     */
    public function getUserStats(int $groupId, int $userId, array $period): array
    {
        return [
            'tasks' => $this->getTasksStats($groupId, $userId, $period),
            'task_statuses' => $this->getTaskStatuses($groupId, $userId, $period),
            'task_priorities' => $this->getTaskPriorities($groupId, $userId, $period),
            'checklist' => $this->getChecklistStats($groupId, $userId, $period),
            'hour_activity' => $this->getHourActivity($groupId, $userId, $period),
            'productivity_index' => $this->getProductivityIndex($groupId, $userId, $period),
        ];
    }

    /**
     * Разбор периода по строковым параметрам и/или диапазону дат.
     */
    public function resolvePeriod(?string $period, ?string $from, ?string $to): array
    {
        if ($from && $to) {
            return [
                'from' => Carbon::parse($from)->startOfDay(),
                'to' => Carbon::parse($to)->endOfDay(),
            ];
        }

        $now = Carbon::now();

        return match ($period) {
            'today' => [
                'from' => $now->copy()->startOfDay(),
                'to' => $now->copy()->endOfDay(),
            ],
            'week' => [
                'from' => $now->copy()->startOfWeek(),
                'to' => $now->copy()->endOfDay(),
            ],
            'month' => [
                'from' => $now->copy()->startOfMonth(),
                'to' => $now->copy()->endOfDay(),
            ],
            'quarter' => [
                'from' => $now->copy()->firstOfQuarter(),
                'to' => $now->copy()->endOfDay(),
            ],
            'year' => [
                'from' => $now->copy()->startOfYear(),
                'to' => $now->copy()->endOfDay(),
            ],
            default => [
                // по умолчанию — текущий месяц
                'from' => $now->copy()->startOfMonth(),
                'to' => $now->copy()->endOfDay(),
            ],
        };
    }

    // ЗАДАЧИ
    public function getTasksStats(int $groupId, int $userId, array $period): array
    {
        $from = $period['from'];
        $to = $period['to'];

        // 1. Завершено в период
        $doneTaskIds = ActivityLog::where('group_id', $groupId)
            ->where('user_id', $userId)
            ->where('entity_type', 'task')
            ->where('action', 'status_updated')
            ->where('changes->new', self::STATUS_DONE)
            ->whereBetween('created_at', [$from, $to])
            ->pluck('entity_id')
            ->unique();

        $done = $doneTaskIds->count();

        // 2. Активные — задачи, которые к концу периода не завершены
        $active = Task::where('assignee_id', $userId)
            ->whereHas('column.space', fn($q) => $q->where('group_id', $groupId))
            ->where('status_id', '!=', self::STATUS_DONE)
            ->where('created_at', '<=', $to)
            ->count();

        // 3. Просроченные (глобально, вне периода)
        $overdue = Task::where('assignee_id', $userId)
            ->whereHas('column.space', fn($q) => $q->where('group_id', $groupId))
            ->where('status_id', '!=', self::STATUS_DONE)
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->count();

        // 4. ВСЕГО = завершено + активные + просроченные
        $total = $done + $active + $overdue;

        // 5. История завершений в период
        $history = ActivityLog::select(
                DB::raw('DATE(created_at) as day'),
                DB::raw('COUNT(*) as count')
            )
            ->where('group_id', $groupId)
            ->where('user_id', $userId)
            ->where('entity_type', 'task')
            ->where('action', 'status_updated')
            ->where('changes->new', self::STATUS_DONE)
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        // 6. Среднее время выполнения
        $avgDuration = $this->getAvgTaskDuration($groupId, $userId, $period);

        return [
            'total' => $total,
            'done' => $done,
            'active' => $active,
            'overdue' => $overdue,
            'history' => $history,
            'avg_duration_hours' => $avgDuration,
        ];
    }

    /**
     * Среднее время выполнения задач: берём задачи,
     * для которых лог перехода в DONE попал в период.
     */
    private function getAvgTaskDuration(int $groupId, int $userId, array $period): float
    {
        $from = $period['from'];
        $to = $period['to'];

        $logs = ActivityLog::where('group_id', $groupId)
            ->where('user_id', $userId)
            ->where('entity_type', 'task')
            ->where('action', 'status_updated')
            ->where('changes->new', self::STATUS_DONE)
            ->whereBetween('created_at', [$from, $to])
            ->get();

        if ($logs->isEmpty()) {
            return 0;
        }

        $taskIds = $logs->pluck('entity_id')->unique();
        $tasks = Task::whereIn('id', $taskIds)->get()->keyBy('id');

        $durations = [];

        foreach ($logs as $log) {
            $task = $tasks->get($log->entity_id);
            if (!$task) continue;

            $start = Carbon::parse($task->created_at);
            $end   = Carbon::parse($log->created_at);

            $durations[] = $start->diffInHours($end);
        }

        if (!count($durations)) {
            return 0;
        }

        return round(array_sum($durations) / count($durations), 1);
    }

    // Диаграмма статусов
    public function getTaskStatuses(int $groupId, int $userId, array $period): array
    {
        $from = $period['from'];
        $to = $period['to'];

        $statuses = Task::where('assignee_id', $userId)
            ->whereHas('column.space', fn($q) => $q->where('group_id', $groupId))
            ->whereBetween('created_at', [$from, $to])
            ->select('status_id', DB::raw('COUNT(*) as count'))
            ->groupBy('status_id')
            ->pluck('count', 'status_id');

        return [
            'created' => $statuses[self::STATUS_CREATED] ?? 0,
            'in_progress' => $statuses[self::STATUS_IN_PROGRESS] ?? 0,
            'done' => $statuses[self::STATUS_DONE] ?? 0,
        ];
    }

    // Диаграмма приоритетов
    public function getTaskPriorities(int $groupId, int $userId, array $period): array
    {
        $from = $period['from'];
        $to = $period['to'];

        $priorities = Task::where('assignee_id', $userId)
            ->whereHas('column.space', fn($q) => $q->where('group_id', $groupId))
            ->whereBetween('created_at', [$from, $to])
            ->where('status_id', '!=', self::STATUS_DONE)
            ->select('priority_id', DB::raw('COUNT(*) as count'))
            ->groupBy('priority_id')
            ->pluck('count', 'priority_id');

        return [
            'low' => $priorities[1] ?? 0,
            'medium' => $priorities[2] ?? 0,
            'high' => $priorities[3] ?? 0,
        ];
    }

    // Чек-листы
    public function getChecklistStats(int $groupId, int $userId, array $period): array
    {
        $from = $period['from'];
        $to = $period['to'];

        // DONE — пункты чек-листа, которые были выполнены В ПЕРИОД
        $done = ChecklistItem::join('checklists', 'checklists.id', '=', 'checklist_items.checklist_id')
            ->join('tasks', 'tasks.id', '=', 'checklists.task_id')
            ->join('columns', 'columns.id', '=', 'tasks.column_id')
            ->join('spaces', 'spaces.id', '=', 'columns.space_id')
            ->where('spaces.group_id', $groupId)
            ->where(function ($q) use ($userId) {
                $q->where('checklist_items.assignee_id', $userId)
                ->orWhere(function ($q2) use ($userId) {
                    $q2->whereNull('checklist_items.assignee_id')
                        ->where('tasks.assignee_id', $userId);
                });
            })
            ->where('checklist_items.completed', 1)
            ->whereBetween('checklist_items.updated_at', [$from, $to])
            ->count();

        // Все 
        $items = ChecklistItem::select('checklist_items.*')
            ->join('checklists', 'checklists.id', '=', 'checklist_items.checklist_id')
            ->join('tasks', 'tasks.id', '=', 'checklists.task_id')
            ->join('columns', 'columns.id', '=', 'tasks.column_id')
            ->join('spaces', 'spaces.id', '=', 'columns.space_id')
            ->where('spaces.group_id', $groupId)
            ->where(function ($q) use ($userId) {
                $q->where('checklist_items.assignee_id', $userId)
                ->orWhere(function ($q2) use ($userId) {
                    $q2->whereNull('checklist_items.assignee_id')
                        ->where('tasks.assignee_id', $userId);
                });
            })
            ->get();


        // OVERDUE — просроченные
        $overdue = $items
            ->where('completed', 0)
            ->filter(fn($i) =>
                $i->due_date &&
                Carbon::parse($i->due_date)->isPast()
            )
            ->count();


        // OTHERS — все невыполненные, кроме просроченных
        $others = $items
            ->where('completed', 0)
            ->filter(fn($i) =>
                !$i->due_date || Carbon::parse($i->due_date)->isFuture()
            )
            ->count();

        //TOTAL
        $total = $done + $overdue + $others;

        return [
            'done' => $done,
            'overdue' => $overdue,
            'others' => $others,
            'total' => $total,
            'progress_percent' => $total ? round(($done / $total) * 100) : 0,
        ];
    }

    // Активность по часам
    public function getHourActivity(int $groupId, int $userId, array $period, string $timezone = 'Europe/Moscow'): array
    {
        $from = $period['from'];
        $to = $period['to'];

        $offset = Carbon::now($timezone)->format('P');

        $raw = ActivityLog::select(
                DB::raw("HOUR(CONVERT_TZ(created_at, '+00:00', '{$offset}')) as hour"),
                DB::raw('COUNT(*) as count')
            )
            ->where('group_id', $groupId)
            ->where('user_id', $userId)
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('hour')
            ->pluck('count', 'hour')
            ->toArray();


        $filled = [];
        for ($h = 0; $h < 24; $h++) {
            $filled[$h] = $raw[$h] ?? 0;
        }

        return $filled;
    }

    // Индекс продуктивности
    public function getProductivityIndex(int $groupId, int $userId, array $period): int
    {
        $from = $period['from'];
        $to   = $period['to'];

        // Закрытые задачи в периоде
        $closedTasks = ActivityLog::where('group_id', $groupId)
            ->where('user_id', $userId)
            ->where('entity_type', 'task')
            ->where('action', 'status_updated')
            ->where('changes->new', self::STATUS_DONE)
            ->whereBetween('created_at', [$from, $to])
            ->count();

        // Выполненные пункты чек-листов в периоде
        $closedChecklist = ChecklistItem::join('checklists', 'checklists.id', '=', 'checklist_items.checklist_id')
            ->join('tasks', 'tasks.id', '=', 'checklists.task_id')
            ->join('columns', 'columns.id', '=', 'tasks.column_id')
            ->join('spaces', 'spaces.id', '=', 'columns.space_id')
            ->where('spaces.group_id', $groupId)
            ->where(function ($q) use ($userId) {
                $q->where('checklist_items.assignee_id', $userId)
                  ->orWhere(function ($q2) use ($userId) {
                      $q2->whereNull('checklist_items.assignee_id')
                         ->where('tasks.assignee_id', $userId);
                  });
            })
            ->where('checklist_items.completed', 1)
            ->whereBetween('checklist_items.updated_at', [$from, $to])
            ->count();

        return $closedTasks * 3 + $closedChecklist;
    }
}
