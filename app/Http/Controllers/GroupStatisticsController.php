<?php

namespace App\Http\Controllers;

use App\Services\GroupStatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupStatisticsController extends Controller
{
    public function __construct(
        private GroupStatisticsService $service
    ) {}

    public function topUsers(Request $request): JsonResponse
    {
        $groupId = (int) $request->query('group_id');
        $period  = $request->query('period');
        $dateFrom = $request->query('date_from');
        $dateTo   = $request->query('date_to');

        if (!$groupId) {
            return response()->json(['message' => 'group_id обязателен'], 400);
        }

        $range = $this->service->resolvePeriod($period, $dateFrom, $dateTo);

        $data = $this->service->getTopUsers($groupId, $range);

        return response()->json($data);
    }

    public function tasksDynamics(Request $request): JsonResponse
    {
        $groupId = (int) $request->query('group_id');
        $period  = $request->query('period');
        $dateFrom = $request->query('date_from');
        $dateTo   = $request->query('date_to');

        if (!$groupId) {
            return response()->json(['message' => 'group_id обязателен'], 400);
        }

        $range = $this->service->resolvePeriod($period, $dateFrom, $dateTo);

        $data = $this->service->getTasksDynamics($groupId, $range);

        return response()->json($data);
    }

    public function workload(Request $request): JsonResponse
    {
        $groupId = (int) $request->query('group_id');
        $period  = $request->query('period');
        $dateFrom = $request->query('date_from');
        $dateTo   = $request->query('date_to');

        if (!$groupId) {
            return response()->json(['message' => 'group_id обязателен'], 400);
        }

        $range = $this->service->resolvePeriod($period, $dateFrom, $dateTo);

        $data = $this->service->getWorkload($groupId, $range);

        return response()->json($data);
    }

    public function spaces(Request $request): JsonResponse
    {
        $groupId = (int) $request->query('group_id');
        $period  = $request->query('period');
        $dateFrom = $request->query('date_from');
        $dateTo   = $request->query('date_to');

        if (!$groupId) {
            return response()->json(['message' => 'group_id обязателен'], 400);
        }

        $range = $this->service->resolvePeriod($period, $dateFrom, $dateTo);

        $data = $this->service->getSpacesStats($groupId, $range);

        return response()->json($data);
    }

    public function overdue(Request $request): JsonResponse
    {
        $groupId = (int) $request->query('group_id');
        $period  = $request->query('period');
        $dateFrom = $request->query('date_from');
        $dateTo   = $request->query('date_to');

        if (!$groupId) {
            return response()->json(['message' => 'group_id обязателен'], 400);
        }

        $range = $this->service->resolvePeriod($period, $dateFrom, $dateTo);

        $data = $this->service->getOverdueStats($groupId, $range);

        return response()->json($data);
    }

    public function teamHours(Request $request): JsonResponse
    {
        $groupId = (int) $request->query('group_id');
        $period  = $request->query('period');
        $dateFrom = $request->query('date_from');
        $dateTo   = $request->query('date_to');

        if (!$groupId) {
            return response()->json(['message' => 'group_id обязателен'], 400);
        }

        $range = $this->service->resolvePeriod($period, $dateFrom, $dateTo);

        $pref = $request->user()->preference()->first();
        $timezone = $pref?->timezone ?? 'Europe/Moscow';

        $data = $this->service->getTeamHours($groupId, $range, $timezone);

        return response()->json($data);
    }
}