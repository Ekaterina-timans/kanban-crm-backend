<?php

namespace App\Http\Controllers;

use App\Services\UserStatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserStatisticsController extends Controller
{
    private UserStatisticsService $service;

    public function __construct(UserStatisticsService $service)
    {
        $this->service = $service;
    }

    /**
     * Старый метод — возвращает ВСЁ.
     * Оставляем без изменений.
     */
    public function personal(Request $request): JsonResponse
    {
        $groupId = (int) $request->query('group_id');
        $userId  = (int) $request->query('user_id');

        $period = $request->query('period');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        if (!$groupId || !$userId) {
            return response()->json(['message' => 'group_id и user_id обязательны'], 400);
        }

        $range = $this->service->resolvePeriod($period, $dateFrom, $dateTo);

        $data = $this->service->getUserStats($groupId, $userId, $range);

        return response()->json($data);
    }


    /**
     * 1. Статистика задач
     */
    public function tasks(Request $request): JsonResponse
    {
        $groupId = (int) $request->query('group_id');
        $userId  = (int) $request->query('user_id');

        if (!$groupId || !$userId) {
            return response()->json(['message' => 'group_id и user_id обязательны'], 400);
        }

        $period = $request->query('period');
        $from = $request->query('date_from');
        $to = $request->query('date_to');

        $range = $this->service->resolvePeriod($period, $from, $to);

        return response()->json(
            $this->service->getTasksStats($groupId, $userId, $range)
        );
    }


    /**
     * 2. Диаграмма статусов задач
     */
    public function statuses(Request $request): JsonResponse
    {
        $groupId = (int) $request->query('group_id');
        $userId  = (int) $request->query('user_id');

        $period = $request->query('period');
        $from = $request->query('date_from');
        $to = $request->query('date_to');

        $range = $this->service->resolvePeriod($period, $from, $to);

        return response()->json(
            $this->service->getTaskStatuses($groupId, $userId, $range)
        );
    }


    /**
     * 3. Приоритеты задач
     */
    public function priorities(Request $request): JsonResponse
    {
        $groupId = (int) $request->query('group_id');
        $userId  = (int) $request->query('user_id');

        $period = $request->query('period');
        $from = $request->query('date_from');
        $to = $request->query('date_to');

        $range = $this->service->resolvePeriod($period, $from, $to);

        return response()->json(
            $this->service->getTaskPriorities($groupId, $userId, $range)
        );
    }


    /**
     * 4. Чек-листы
     */
    public function checklist(Request $request): JsonResponse
    {
        $groupId = (int) $request->query('group_id');
        $userId  = (int) $request->query('user_id');

        $period = $request->query('period');
        $from = $request->query('date_from');
        $to = $request->query('date_to');

        $range = $this->service->resolvePeriod($period, $from, $to);

        return response()->json(
            $this->service->getChecklistStats($groupId, $userId, $range)
        );
    }


    /**
     * 5. Часовая активность
     */
    public function hourActivity(Request $request): JsonResponse
    {
        $groupId = (int) $request->query('group_id');
        $userId  = (int) $request->query('user_id');

        $period = $request->query('period');
        $from = $request->query('date_from');
        $to = $request->query('date_to');

        $range = $this->service->resolvePeriod($period, $from, $to);

        $pref = $request->user()->preference()->first();
        $timezone = $pref?->timezone ?? 'Europe/Moscow';

        return response()->json(
            $this->service->getHourActivity($groupId, $userId, $range, $timezone)
        );
    }


    /**
     * Индекс продуктивности
     */
    public function productivity(Request $request): JsonResponse
    {
        $groupId = (int) $request->query('group_id');
        $userId  = (int) $request->query('user_id');

        $period = $request->query('period');
        $from = $request->query('date_from');
        $to = $request->query('date_to');

        $range = $this->service->resolvePeriod($period, $from, $to);

        return response()->json(
            $this->service->getProductivityIndex($groupId, $userId, $range)
        );
    }
}
