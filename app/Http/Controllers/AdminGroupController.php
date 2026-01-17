<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Group;
use Illuminate\Http\Request;

class AdminGroupController extends Controller
{
    /**
     * Список групп:
     * - поиск
     * - фильтр active/passive (ДИНАМИЧЕСКИЙ)
     * - сортировка по активности
     */
    public function index(Request $request)
    {
        $query = Group::query()
            ->withCount('users')
            ->withCount('spaces');

        // Поиск
        if ($search = $request->query('q')) {
            $query->where('name', 'like', "%$search%");
        }

        // Добавим подзапрос активности для сортировки/фильтра
        $query->withCount([
            'activityLogs as activity_score' => function ($q) {
                $q->where('created_at', '>=', now()->subDays(14));
            }
        ]);

        // ФИЛЬТР active/passive
        if ($filter = $request->query('status')) {
            if ($filter === 'active') {
                $query->having('activity_score', '>', 0);
            } elseif ($filter === 'passive') {
                $query->having('activity_score', '=', 0);
            }
        }

        // Сортировка
        if ($request->query('sort') === 'activity') {
            $query->orderByDesc('activity_score');
        } else {
            $query->orderBy('id', 'desc');
        }

        return response()->json($query->paginate(20));
    }

    /**
     * Детальная информация по группе
     */
    public function show(Group $group)
    {
        $group->load([
            'creator:id,name,email,avatar',
            'users' => fn($q) => $q->select('users.id','users.name','users.email','users.avatar')
                ->withPivot(['role','status']),
            'spaces:id,group_id,name'
        ]);

        // Активность за 14 дней
        $activity14 = ActivityLog::where('group_id', $group->id)
            ->where('created_at', '>=', now()->subDays(14))
            ->count();

        // Статус группы — вычисляемый
        $computedStatus = $activity14 > 0 ? 'active' : 'passive';

        return response()->json([
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'computed_status' => $computedStatus,
                'created_at' => $group->created_at,
                'creator' => $group->creator,
                'members_count' => $group->users->count(),
                'spaces_count' => $group->spaces->count(),
            ],

            'members' => $group->users->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->name ?? $u->email,
                'email' => $u->email,
                'role' => $u->pivot->role,
                'status' => $u->pivot->status,
            ]),

            'spaces' => $group->spaces->map(fn($s) => [
                'id' => $s->id,
                'name' => $s->name,
            ]),

            'activity_last_14_days' => $activity14,
        ]);
    }

    /**
     * Удаление группы
     */
    public function destroy(Group $group)
    {
        $group->delete();
        return response()->json(['message' => 'Группа удалена']);
    }
}
