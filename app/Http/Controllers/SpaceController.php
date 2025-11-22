<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\Permission;
use App\Models\Space;
use App\Services\ActivityLogService;
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
    public function store(Request $request): JsonResponse
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
        app(SpaceUserController::class)->assignDefaultPermissions($spaceUser);

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

    public function show($id)
    {
        Log::info('SpaceController@show called', ['id' => $id]);
        // Получаем пространство по ID с колонками и задачами,
        // но сортируем колонки по position!
        $space = Space::with([
            'columns' => function ($query) {
                $query->orderBy('position');
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
