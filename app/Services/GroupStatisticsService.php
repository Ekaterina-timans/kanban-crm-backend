<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ChecklistItem;
use App\Models\Space;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GroupStatisticsService
{
    /**
     * Унифицированная обработка периода — как в UserStatisticsService
     */
    public function resolvePeriod(?string $preset, ?string $from, ?string $to): array
    {
        if ($preset !== 'range') {
            return app(UserStatisticsService::class)->resolvePeriod($preset, $from, $to);
        }

        return [
            'from' => Carbon::parse($from)->startOfDay(),
            'to'   => Carbon::parse($to)->endOfDay(),
        ];
    }

    // ----------------------------------------------------------------------
    // 1. ТОП-5 УЧАСТНИКОВ
    // ----------------------------------------------------------------------

    public function getTopUsers(int $groupId, array $period): array
    {
        $from = $period['from'];
        $to   = $period['to'];

        // Завершённые задачи
        $doneTasksByUser = ActivityLog::select(
                'tasks.assignee_id as user_id',
                DB::raw('COUNT(DISTINCT activity_logs.entity_id) as done_tasks')
            )
            ->join('tasks', 'tasks.id', '=', 'activity_logs.entity_id')
            ->join('columns', 'columns.id', '=', 'tasks.column_id')
            ->join('spaces', 'spaces.id', '=', 'columns.space_id')
            ->where('spaces.group_id', $groupId)
            ->where('activity_logs.entity_type', 'task')
            ->where('activity_logs.action', 'status_updated')
            ->where('activity_logs.changes->new', UserStatisticsService::STATUS_DONE)
            ->whereBetween('activity_logs.created_at', [$from, $to])
            ->groupBy('tasks.assignee_id')
            ->pluck('done_tasks', 'user_id')
            ->toArray();

        // Выполненные чек-листы
        $checklistDone = ChecklistItem::select(
                DB::raw('COALESCE(checklist_items.assignee_id, tasks.assignee_id) as user_id'),
                DB::raw('COUNT(*) as done_items')
            )
            ->join('checklists', 'checklists.id', '=', 'checklist_items.checklist_id')
            ->join('tasks', 'tasks.id', '=', 'checklists.task_id')
            ->join('columns', 'columns.id', '=', 'tasks.column_id')
            ->join('spaces', 'spaces.id', '=', 'columns.space_id')
            ->where('spaces.group_id', $groupId)
            ->where('checklist_items.completed', 1)
            ->whereBetween('checklist_items.updated_at', [$from, $to])
            ->groupBy('user_id')
            ->pluck('done_items', 'user_id')
            ->toArray();

        // Собрать список пользователей
        $userIds = array_unique(array_merge(
            array_keys($doneTasksByUser),
            array_keys($checklistDone)
        ));

        $userIds = array_values(array_filter($userIds, function ($id) {
            return !empty($id);
        }));

        $users = User::whereIn('id', $userIds)
            ->get(['id', 'name', 'email', 'avatar'])
            ->keyBy('id');

        $result = [];

        foreach ($userIds as $uid) {
            $user = $users->get($uid);

            $tasksDone = $doneTasksByUser[$uid] ?? 0;
            $checkDone = $checklistDone[$uid] ?? 0;

            $result[] = [
                'user_id' => $uid,
                'name'    => $user->name ?? $user->email ?? 'Без имени',
                'avatar'  => $user->avatar ?? null,
                'tasks_done' => $tasksDone,
                'checklist_done' => $checkDone,
                'productivity_index' => $tasksDone * 3 + $checkDone,
            ];
        }

        usort($result, fn($a, $b) => $b['productivity_index'] <=> $a['productivity_index']);

        return array_slice($result, 0, 5);
    }

    // ----------------------------------------------------------------------
    // 2. ДИНАМИКА ЗАДАЧ
    // ----------------------------------------------------------------------

    public function getTasksDynamics(int $groupId, array $period): array
    {
        $from = $period['from'];
        $to   = $period['to'];

        // Получаем все spaces группы
        $spaces = Space::where('group_id', $groupId)
            ->get(['id', 'name'])
            ->keyBy('id');

        // Формируем список дней периода
        $days = [];
        $cursor = $from->copy();
        while ($cursor <= $to) {
            $days[] = $cursor->toDateString();
            $cursor->addDay();
        }

        // -----------------------------
        // 1. CREATED — задачи, созданные в период по space
        // -----------------------------
        $createdRaw = Task::select(
                'spaces.id as space_id',
                DB::raw('DATE(tasks.created_at) as day'),
                DB::raw('COUNT(*) as count')
            )
            ->join('columns', 'columns.id', '=', 'tasks.column_id')
            ->join('spaces', 'spaces.id', '=', 'columns.space_id')
            ->where('spaces.group_id', $groupId)
            ->whereBetween('tasks.created_at', [$from, $to])
            ->groupBy('space_id', 'day')
            ->get();

        $created = [];
        foreach ($createdRaw as $row) {
            $created[$row->space_id][$row->day] = (int) $row->count;
        }

        // -----------------------------
        // 2. DONE — завершённые в период по space
        // -----------------------------
        $doneRaw = ActivityLog::select(
                'spaces.id as space_id',
                DB::raw('DATE(activity_logs.created_at) as day'),
                DB::raw('COUNT(DISTINCT activity_logs.entity_id) as count')
            )
            ->join('tasks', 'tasks.id', '=', 'activity_logs.entity_id')
            ->join('columns', 'columns.id', '=', 'tasks.column_id')
            ->join('spaces', 'spaces.id', '=', 'columns.space_id')
            ->where('spaces.group_id', $groupId)
            ->where('activity_logs.entity_type', 'task')
            ->where('activity_logs.action', 'status_updated')
            ->where('activity_logs.changes->new', UserStatisticsService::STATUS_DONE)
            ->whereBetween('activity_logs.created_at', [$from, $to])
            ->groupBy('space_id', 'day')
            ->get();

        $done = [];
        foreach ($doneRaw as $row) {
            $done[$row->space_id][$row->day] = (int) $row->count;
        }

        // -----------------------------
        // 3. Сбор итогов по каждому space + баланс
        // -----------------------------
        $result = [];

        foreach ($spaces as $spaceId => $space) {
            $balance = 0;
            $rows = [];

            foreach ($days as $day) {
                $c = $created[$spaceId][$day] ?? 0;
                $d = $done[$spaceId][$day] ?? 0;

                $balance += ($c - $d);

                $rows[] = [
                    'day'     => $day,
                    'created' => $c,
                    'done'    => $d,
                    'balance' => $balance,
                ];
            }

            $result[] = [
                'space_id'   => $spaceId,
                'space_name' => $space->name,
                'dynamics'   => $rows,
            ];
        }

        return $result;
    }

    // ----------------------------------------------------------------------
    // 3. ЗАГРУЖЕННОСТЬ УЧАСТНИКОВ
    // ----------------------------------------------------------------------

    public function getWorkload(int $groupId, array $period): array
    {
        $now = now();
        $soon = $now->copy()->addDays(7);

        // Активные задачи
        $activeTasks = Task::select(
                'tasks.assignee_id as user_id',
                DB::raw('COUNT(*) as active_tasks')
            )
            ->join('columns', 'columns.id', '=', 'tasks.column_id')
            ->join('spaces', 'spaces.id', '=', 'columns.space_id')
            ->where('spaces.group_id', $groupId)
            ->where('tasks.status_id', '!=', UserStatisticsService::STATUS_DONE)
            ->groupBy('tasks.assignee_id')
            ->pluck('active_tasks', 'user_id')
            ->toArray();

        // Дедлайны ближайшие 7 дней
        $dueSoon = Task::select(
                'tasks.assignee_id as user_id',
                DB::raw('COUNT(*) as due_soon')
            )
            ->join('columns', 'columns.id', '=', 'tasks.column_id')
            ->join('spaces', 'spaces.id', '=', 'columns.space_id')
            ->where('spaces.group_id', $groupId)
            ->whereNotNull('tasks.due_date')
            ->whereBetween('tasks.due_date', [$now, $soon])
            ->where('tasks.status_id', '!=', UserStatisticsService::STATUS_DONE)
            ->groupBy('tasks.assignee_id')
            ->pluck('due_soon', 'user_id')
            ->toArray();

        // Активные чек-листы
        $checklistActive = ChecklistItem::select(
                DB::raw('COALESCE(checklist_items.assignee_id, tasks.assignee_id) as user_id'),
                DB::raw('COUNT(*) as checklist_active')
            )
            ->join('checklists', 'checklists.id', '=', 'checklist_items.checklist_id')
            ->join('tasks', 'tasks.id', '=', 'checklists.task_id')
            ->join('columns', 'columns.id', '=', 'tasks.column_id')
            ->join('spaces', 'spaces.id', '=', 'columns.space_id')
            ->where('spaces.group_id', $groupId)
            ->where('checklist_items.completed', 0)
            ->groupBy('user_id')
            ->pluck('checklist_active', 'user_id')
            ->toArray();

        // Список юзеров
        $userIds = array_unique(array_merge(
            array_keys($activeTasks),
            array_keys($dueSoon),
            array_keys($checklistActive)
        ));

        // убираем null / 0 / '' из списка id пользователей
        $userIds = array_values(array_filter($userIds, function ($id) {
            return !empty($id);
        }));

        $users = User::whereIn('id', $userIds)
            ->get(['id', 'name', 'email', 'avatar'])
            ->keyBy('id');

        $result = [];

        foreach ($userIds as $uid) {
            $user = $users->get($uid); // безопасно, без Undefined array key

            $result[] = [
                'user_id' => $uid,
                'name'    => $user->name ?? $user->email ?? 'Без имени',
                'avatar'  => $user->avatar ?? null,
                'active_tasks'     => $activeTasks[$uid] ?? 0,
                'due_soon_tasks'   => $dueSoon[$uid] ?? 0,
                'checklist_active' => $checklistActive[$uid] ?? 0,
            ];
        }

        usort($result, fn($a, $b) => $b['active_tasks'] <=> $a['active_tasks']);

        return $result;
    }

    // ----------------------------------------------------------------------
    // 4. СТАТИСТИКА ПО ПРОЕКТАМ (spaces)
    // ----------------------------------------------------------------------

    public function getSpacesStats(int $groupId, array $period): array
    {
        $from = $period['from'];
        $to   = $period['to'];

        $created = Task::select(
                'spaces.id as space_id',
                'spaces.name as space_name',
                DB::raw('COUNT(*) as created')
            )
            ->join('columns', 'columns.id', '=', 'tasks.column_id')
            ->join('spaces', 'spaces.id', '=', 'columns.space_id')
            ->where('spaces.group_id', $groupId)
            ->whereBetween('tasks.created_at', [$from, $to])
            ->groupBy('spaces.id', 'spaces.name')
            ->get()
            ->keyBy('space_id');

        $doneRaw = ActivityLog::select(
                'spaces.id as space_id',
                DB::raw('COUNT(DISTINCT activity_logs.entity_id) as done')
            )
            ->join('tasks', 'tasks.id', '=', 'activity_logs.entity_id')
            ->join('columns', 'columns.id', '=', 'tasks.column_id')
            ->join('spaces', 'spaces.id', '=', 'columns.space_id')
            ->where('spaces.group_id', $groupId)
            ->where('activity_logs.entity_type', 'task')
            ->where('activity_logs.action', 'status_updated')
            ->where('activity_logs.changes->new', UserStatisticsService::STATUS_DONE)
            ->whereBetween('activity_logs.created_at', [$from, $to])
            ->groupBy('spaces.id')
            ->pluck('done', 'space_id')
            ->toArray();

        $result = [];

        foreach ($created as $spaceId => $row) {
            $done = $doneRaw[$spaceId] ?? 0;

            $result[] = [
                'space_id'   => $spaceId,
                'space_name' => $row->space_name,
                'created'    => $row->created,
                'done'       => $done,
                'net'        => $row->created - $done,
            ];
        }

        usort($result, fn($a, $b) => $b['created'] <=> $a['created']);

        return $result;
    }

    // ----------------------------------------------------------------------
    // 5. ПРОСРОЧКИ
    // ----------------------------------------------------------------------

    public function getOverdueStats(int $groupId, array $period): array
    {
        $now = now();

        $base = Task::query()
            ->join('columns', 'columns.id', '=', 'tasks.column_id')
            ->join('spaces', 'spaces.id', '=', 'columns.space_id')
            ->where('spaces.group_id', $groupId)
            ->whereNotNull('tasks.due_date')
            ->where('tasks.due_date', '<', $now)
            ->where('tasks.status_id', '!=', UserStatisticsService::STATUS_DONE);

        $total = (clone $base)->count();

        $byUser = (clone $base)
            ->select('tasks.assignee_id as user_id', DB::raw('COUNT(*) as overdue'))
            ->groupBy('tasks.assignee_id')
            ->pluck('overdue', 'user_id')
            ->toArray();

        $bySpace = (clone $base)
            ->select('spaces.id as space_id', 'spaces.name as space_name', DB::raw('COUNT(*) as overdue'))
            ->groupBy('spaces.id', 'spaces.name')
            ->get()
            ->toArray();

        // user details
        $userIds = array_keys($byUser);

        // фильтруем пустых
        $userIds = array_values(array_filter($userIds, function ($id) {
            return !empty($id);
        }));

        $users = User::whereIn('id', $userIds)
            ->get(['id', 'name', 'email', 'avatar'])
            ->keyBy('id');

        $byUserFormatted = [];

        foreach ($byUser as $uid => $count) {
            // отдельная ветка для задач без исполнителя
            if (empty($uid)) {
                $byUserFormatted[] = [
                    'user_id' => null,
                    'name'    => 'Без исполнителя',
                    'avatar'  => null,
                    'overdue' => $count,
                ];
                continue;
            }

            $user = $users->get($uid);

            $byUserFormatted[] = [
                'user_id' => $uid,
                'name'    => $user->name ?? $user->email ?? 'Без имени',
                'avatar'  => $user->avatar ?? null,
                'overdue' => $count,
            ];
        }

        usort($byUserFormatted, fn($a, $b) => $b['overdue'] <=> $a['overdue']);

        return [
            'total_overdue' => $total,
            'by_user'       => $byUserFormatted,
            'by_space'      => $bySpace,
        ];
    }

    // ----------------------------------------------------------------------
    // 6. РАБОЧИЕ ЧАСЫ КОМАНДЫ
    // ----------------------------------------------------------------------

    public function getTeamHours(int $groupId, array $period, string $timezone = 'Europe/Moscow'): array
    {
        $from = $period['from'];
        $to   = $period['to'];

        // Получаем смещение для выбранного часового пояса (например, +03:00)
        $offset = Carbon::now($timezone)->format('P');

        // Переводим created_at из UTC (+00:00) в локальное время по offset
        $raw = ActivityLog::select(
                DB::raw("WEEKDAY(CONVERT_TZ(created_at, '+00:00', '{$offset}')) as weekday"),
                DB::raw("HOUR(CONVERT_TZ(created_at, '+00:00', '{$offset}')) as hour"),
                DB::raw('COUNT(*) as count')
            )
            ->where('group_id', $groupId)
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('weekday', 'hour')
            ->get();

        // Инициализируем матрицу 7x24
        $matrix = [];
        for ($w = 0; $w < 7; $w++) {
            for ($h = 0; $h < 24; $h++) {
                $matrix[$w][$h] = 0;
            }
        }

        // Заполняем матрицу
        foreach ($raw as $row) {
            $weekday = (int) $row->weekday;
            $hour    = (int) $row->hour;

            if ($weekday >= 0 && $weekday < 7 && $hour >= 0 && $hour < 24) {
                $matrix[$weekday][$hour] = (int) $row->count;
            }
        }

        return [
            'matrix'   => $matrix,
            'weekdays' => ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],
            'hours'    => range(0, 23),
        ];
    }
}