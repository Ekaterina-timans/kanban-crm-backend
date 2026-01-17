<?php

namespace App\Services;

use App\Models\User;
use App\Models\Group;
use App\Models\ActivityLog;
use Carbon\Carbon;

class AdminStatisticsService
{
    /**
     * Разбор периода — идентично UserStatisticsService
     */
    public function resolvePeriod(?string $period, ?string $from, ?string $to): array
    {
        if ($from && $to) {
            return [
                'from' => Carbon::parse($from)->startOfDay(),
                'to'   => Carbon::parse($to)->endOfDay(),
            ];
        }

        $now = Carbon::now();

        return match ($period) {
            'today' => [
                'from' => $now->copy()->startOfDay(),
                'to'   => $now->copy()->endOfDay(),
            ],
            'week' => [
                'from' => $now->copy()->startOfWeek(),
                'to'   => $now->copy()->endOfDay(),
            ],
            'month' => [
                'from' => $now->copy()->startOfMonth(),
                'to'   => $now->copy()->endOfDay(),
            ],
            'quarter' => [
                'from' => $now->copy()->firstOfQuarter(),
                'to'   => $now->copy()->endOfDay(),
            ],
            'year' => [
                'from' => $now->copy()->startOfYear(),
                'to'   => $now->copy()->endOfDay(),
            ],
            default => [
                'from' => $now->copy()->startOfMonth(),
                'to'   => $now->copy()->endOfDay(),
            ],
        };
    }

    /**
     * 1. Общее количество пользователей
     */
    public function usersTotal(): int
    {
        return User::count();
    }

    /**
     * 2. Заблокированные пользователи глобально
     */
    public function usersBlocked(): int
    {
        return User::where('account_status', 'blocked')->count();
    }

    /**
     * 3. Количество групп
     */
    public function groupsTotal(): int
    {
        return Group::count();
    }

    /**
     * 4. Активные/пассивные группы
     */
    public function groupsActivity(array $period): array
    {
        $from = $period['from'];
        $to   = $period['to'];

        $total = Group::count();

        $activeIds = ActivityLog::whereBetween('created_at', [$from, $to])
            ->pluck('group_id')
            ->filter()
            ->unique();

        $active = $activeIds->count();
        $inactive = $total - $active;

        return [
            'total'    => $total,
            'active'   => $active,
            'inactive' => $inactive,
        ];
    }

    /**
     * 5. Список пассивных групп
     */
    public function inactiveGroups(array $period)
    {
        $from = $period['from'];
        $to   = $period['to'];

        $activeIds = ActivityLog::whereBetween('created_at', [$from, $to])
            ->pluck('group_id')
            ->filter()
            ->unique()
            ->toArray();

        return Group::whereNotIn('id', $activeIds)
            ->with('creator:id,name,email')
            ->get(['id', 'name', 'creator_id', 'created_at']);
    }
}
