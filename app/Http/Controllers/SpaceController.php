<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\Permission;
use App\Models\Space;
use App\Services\ActivityLogService;
use App\Services\SpacePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class SpaceController extends Controller
{
    // Получить все пространства
    public function index(Request $request): JsonResponse
    {
        $groupId = $request->query('group_id') ?? $request->input('group_id');
        $user = $request->user();

        if (!$groupId) {
            return response()->json(['message' => 'group_id обязателен'], 400);
        }

        $group = Group::with(['users'])->find($groupId);
        if (!$group) {
            return response()->json(['message' => 'Группа не найдена'], 404);
        }

        $isGroupAdmin = $group->users()
            ->wherePivot('user_id', $user->id)
            ->wherePivot('role', 'admin')
            ->exists();

        $spaces = Space::with([
            'spaceUsers' => fn($q) => $q->select('id', 'space_id', 'user_id', 'role'),
            'spaceUsers.user:id,name,email,avatar',
        ])
        ->where('group_id', $groupId)
        ->when(!$isGroupAdmin, fn($query) =>
            $query->whereHas('spaceUsers', fn($q) => $q->where('user_id', $user->id))
        )
        ->get();

        return response()->json($spaces);
    }

    // Создать новое пространство
    public function store(Request $request, SpacePermissionService $permissionService): JsonResponse
    {
        $validated = $request->validate([
            'group_id' => 'required|exists:groups,id',
            'name' => 'required|string|max:50',
            'description' => 'nullable|string',
            'background_image' => 'nullable|image|max:2048', // ограничение по размеру 2МБ
            'background_color' => 'nullable|string|size:7'
        ]);

        // Сохраняем изображение, если есть
        if ($request->hasFile('background_image')) {
            $path = $request->file('background_image')->store('spaces', 'public');
            $validated['background_image'] = $path;
        }

        // Создаём пространство
        $space = Space::create($validated);

        // Убедимся, что базовые права существуют
        foreach (Permission::defaultPermissions() as $perm) {
            Permission::firstOrCreate(['name' => $perm['name']], $perm);
        }

        // Добавляем создателя как владельца пространства
        $spaceUser = $space->spaceUsers()->create([
            'user_id' => auth()->id(),
            'role' => 'owner',
        ]);

        // Назначаем дефолтные права для владельца
       $permissionService->assignDefaultPermissions($spaceUser);

        ActivityLogService::log(
            groupId: $space->group_id,
            userId: $request->user()->id,
            entityType: 'space',
            entityId: $space->id,
            action: 'created',
            changes: [
                'name' => $space->name,
                'description' => $space->description,
            ]
        );

        return response()->json(
            $space->load('spaceUsers.user'),
            201
        );
    }

    public function show(Request $request, $id)
    {
        Log::info('SpaceController@show called', ['id' => $id]);

        $filters = $request->validate([
            'task_q' => 'nullable|string|max:100',
            'assignee_id' => 'nullable|integer|exists:users,id',
            'status_id' => 'nullable|integer|exists:statuses,id',
            'priority_id' => 'nullable|integer|exists:priorities,id',
            'due_from' => 'nullable|date',
            'due_to' => 'nullable|date',
            'task_sort' => 'nullable|in:created_at,due_date',
            'task_order' => 'nullable|in:asc,desc',
        ]);

        // фильтр активен, если есть хотя бы одно непустое значение
        $hasFilters = collect($filters)->only([
            'task_q', 'assignee_id', 'status_id', 'priority_id', 'due_from', 'due_to'
        ])->filter(fn($v) => $v !== null && $v !== '')->isNotEmpty();

        $taskSort = $filters['task_sort'] ?? 'created_at';
        $taskOrder = $filters['task_order'] ?? 'desc';

        // Базовый query для задач по фильтру (используем и для with, и для whereHas)
        $applyTaskFilters = function ($query) use ($filters) {
            if (!empty($filters['task_q'])) {
                $q = $filters['task_q'];
                $query->where(function ($sub) use ($q) {
                    $sub->where('name', 'like', "%{$q}%")
                        ->orWhere('description', 'like', "%{$q}%");
                });
            }

            if (!empty($filters['assignee_id'])) {
                $query->where('assignee_id', $filters['assignee_id']);
            }

            if (!empty($filters['status_id'])) {
                $query->where('status_id', $filters['status_id']);
            }

            if (!empty($filters['priority_id'])) {
                $query->where('priority_id', $filters['priority_id']);
            }

            if (!empty($filters['due_from'])) {
                $query->whereDate('due_date', '>=', $filters['due_from']);
            }

            if (!empty($filters['due_to'])) {
                $query->whereDate('due_date', '<=', $filters['due_to']);
            }
        };

        $applyTaskSorting = function ($query) use ($taskSort, $taskOrder) {
            if ($taskSort === 'due_date') {
                // MySQL/MariaDB: задачи без due_date будут внизу
                $query->orderByRaw('due_date IS NULL ASC');
                $query->orderBy('due_date', $taskOrder);

                // чтобы порядок был стабильный для задач с одинаковой due_date
                $query->orderBy('created_at', 'desc');
            } else {
                $query->orderBy('created_at', $taskOrder);
            }
        };

        $space = Space::with([
            'columns' => function ($colQ) use ($hasFilters, $applyTaskFilters) {
                $colQ->orderBy('position');

                // если фильтры активны — возвращаем только колонки, где есть задачи по фильтру
                if ($hasFilters) {
                    $colQ->whereHas('tasks', function ($taskQ) use ($applyTaskFilters) {
                        $applyTaskFilters($taskQ);
                    });
                }
            },

            'columns.tasks' => function ($taskQ) use ($hasFilters, $applyTaskFilters, $applyTaskSorting) {
                if ($hasFilters) {
                    $applyTaskFilters($taskQ);
                }

                $applyTaskSorting($taskQ);
            },

            'columns.tasks.assignee:id,name,email,avatar',
        ])->find($id);

        if (!$space) {
            return response()->json(['message' => 'Space not found'], 404);
        }

        return response()->json($space);
    }

    public function update(Request $request, $id): JsonResponse
    {

        $validatedData = $request->validate([
            'name' => 'sometimes|required|string|max:50',
            'description' => 'sometimes|nullable|string',
            'background_image' => 'sometimes|nullable|image|max:2048',
            'background_color' => 'sometimes|nullable|string|size:7'
        ]);

        $space = Space::findOrFail($id);
        $oldData = $space->only(['name', 'description', 'background_color']);

        if ($request->hasFile('background_image')) {
            if ($space->background_image) {
                Storage::disk('public')->delete($space->background_image);
            }
            $path = $request->file('background_image')->store('spaces', 'public');
            $validatedData['background_image'] = $path;
        }

        $space->update($validatedData);

        ActivityLogService::log(
            groupId: $space->group_id,
            userId: $request->user()->id,
            entityType: 'space',
            entityId: $space->id,
            action: 'updated',
            changes: [
                'old' => $oldData,
                'new' => $space->only(['name', 'description', 'background_color']),
            ]
        );

        return response()->json($space, 200);
    }

    public function destroy($id): JsonResponse
    {
        $space = Space::find($id);

        if (!$space) {
            return response()->json(['message' => 'Пространство не найдено'], 404);
        }

        $snapshot = $space->only(['id', 'name', 'description']);

        if ($space->background_image) {
            Storage::disk('public')->delete($space->background_image);
        }

        $space->delete();

        ActivityLogService::log(
            groupId: $space->group_id,
            userId: auth()->id(),
            entityType: 'space',
            entityId: $snapshot['id'],
            action: 'deleted',
            changes: $snapshot
        );

        return response()->json(['message' => 'Пространство успешно удалено'], 200);
    }
}
