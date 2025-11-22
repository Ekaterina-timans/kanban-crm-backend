<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    /**
     * Получить историю действий с фильтрами
     */
    public function index(Request $request): JsonResponse
    {
        $groupId = $request->query('group_id');
        if (!$groupId) {
            return response()->json(['message' => 'group_id обязателен'], 400);
        }

        $query = ActivityLog::with('user:id,name,email,avatar')
            ->where('group_id', $groupId);

        /** Фильтр по пользователю */
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }

        /** Фильтр по типу сущности (spaces, tasks, participants...) */
        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->query('entity_type'));
        }

        /** Фильтр по типу действия */
        if ($request->filled('action')) {
            $query->where('action', $request->query('action'));
        }

        /** Фильтр по дате (день) */
        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->query('date'));
        }

        /** Для диапазона дат */
        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->whereBetween('created_at', [
                $request->query('date_from'),
                $request->query('date_to'),
            ]);
        }

        /** Сортировка: новые сверху */
        $logs = $query->orderByDesc('created_at')->get();

        return response()->json($logs);
    }
}
