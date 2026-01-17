<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Space;
use App\Models\SpaceUser;
use App\Services\SpacePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpaceUserController extends Controller
{
    /**
     * Получить всех пользователей пространства с ролями и правами
     */
    public function index(Space $space): JsonResponse
    {
        $user = auth()->user();

        $group = $space->group;

        $isGroupAdmin = $group->users()
            ->wherePivot('user_id', $user->id)
            ->wherePivot('role', 'admin')
            ->exists();

        $users = $space->spaceUsers()
            ->with(['user:id,name,email,avatar'])
            ->get(['id', 'space_id', 'user_id', 'role']);

        // Админ группы не отображается, если не состоит в space_users
        if ($isGroupAdmin && !$users->contains('user_id', $user->id)) {
            return response()->json($users);
        }

        return response()->json($users);
    }

    /**
     * Добавить пользователя в пространство
     */
    public function store(Request $request, Space $space): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'role' => 'required|in:owner,editor,viewer',
        ]);

        $spaceUser = $space->spaceUsers()->create($data);

        // Назначаем базовые права для роли
        app(SpacePermissionService::class)->assignDefaultPermissions($spaceUser);

        return response()->json($spaceUser->load('permissions'), 201);
    }

    public function role(Space $space): JsonResponse
    {
        $user = auth()->user();

        $spaceUser = $space->spaceUsers()
            ->where('user_id', $user->id)
            ->with('permissions')
            ->first();

        // Проверяем, админ ли группы
        $isGroupAdmin = $space->group
            ->users()
            ->wherePivot('user_id', $user->id)
            ->wherePivot('role', 'admin')
            ->exists();

        // Если пользователь не состоит, но админ группы — считаем его owner
        if (!$spaceUser && $isGroupAdmin) {
            return response()->json([
                'role' => 'owner',
                'permissions' => Permission::pluck('name'),
                'virtual' => true,
            ]);
        }

        if (!$spaceUser) {
            return response()->json(['message' => 'Вы не состоите в этом пространстве'], 404);
        }

        return response()->json([
            'role' => $spaceUser->role,
            'permissions' => $spaceUser->permissions->pluck('name'),
            'virtual' => false,
        ]);
    }

    public function updateRole(Request $request, SpaceUser $spaceUser): JsonResponse
    {
        $data = $request->validate([
            'role' => 'required|in:owner,editor,viewer',
        ]);

        $spaceUser->update(['role' => $data['role']]);

        // При смене роли — обновляем дефолтные права
        app(SpacePermissionService::class)->assignDefaultPermissions($spaceUser);

        return response()->json([
            'message' => 'Role updated successfully',
            'space_user' => $spaceUser->load('permissions')
        ]);
    }

    /**
     * Получить данные и права конкретного пользователя пространства
     */
    public function show(SpaceUser $spaceUser): JsonResponse
    {
        return response()->json(
            $spaceUser->load(['user:id,name,email', 'permissions:id,name,description'])
        );
    }

    /**
     * Обновить права пользователя
     */
    public function updatePermissions(Request $request, SpaceUser $spaceUser): JsonResponse
    {
        $data = $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        $spaceUser->permissions()->sync($data['permissions']);
        return response()->json($spaceUser->load('permissions'));
    }

    /**
     * Удалить пользователя из пространства
     */
    public function destroy(SpaceUser $spaceUser): JsonResponse
    {
        $spaceUser->delete();
        return response()->json(['message' => 'User removed from space']);
    }
}
