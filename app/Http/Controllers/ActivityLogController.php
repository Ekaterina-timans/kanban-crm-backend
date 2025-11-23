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

        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 10);

        $query = ActivityLog::with('user:id,name,email,avatar')
            ->where('group_id', $groupId);

        /** Фильтр по пользователю */
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }

        /** Фильтр по типу сущности */
        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->query('entity_type'));
        }

        /** Фильтр по типу действия */
        if ($request->filled('action_group')) {

            $map = [
                'created' => [
                    'created',
                    'checklist_created',
                    'checklist_item_created',
                    'comment_created',
                    'invited',
                    'task_created'
                ],

                'updated' => [
                    'updated',
                    'renamed',
                    'column_changed',
                    'description_updated',
                    'priority_updated',
                    'due_date_updated',
                    'assignee_updated',
                    'checklist_updated',
                    'checklist_item_updated'
                ],

                'deleted' => [
                    'deleted',
                    'checklist_deleted',
                    'checklist_item_deleted',
                    'comment_deleted',
                    'task_deleted'
                ],

                'other' => [
                    'order_updated'
                ]
            ];

            $group = $request->query('action_group');

            if (isset($map[$group])) {
                $query->whereIn('action', $map[$group]);
            }
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
        $logs = $query
            ->orderByDesc('created_at')
            ->paginate($limit, ['*'], 'page', $page);

        return response()->json([
            'data'  => $logs->items(),
            'total' => $logs->total(),
            'page'  => $logs->currentPage(),
            'limit' => $logs->perPage()
        ]);
    }
}
