<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\User;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    /**
     * Список пользователей
     */
    public function index(Request $request)
    {
       $query = User::query()
        ->where('id', '!=', $request->user()->id);

        // Поиск
        if ($search = $request->query('q')) {
            $query->where(function ($q2) use ($search) {
                $q2->where('email', 'like', "%$search%")
                   ->orWhere('name', 'like', "%$search%");
            });
        }

        // Фильтр по статусу
        if ($status = $request->query('status')) {
            $query->where('account_status', $status);
        }

        return response()->json($query->orderBy('id', 'desc')->paginate(10));
    }

    /**
     * Один пользователь
     */
    public function show(User $user)
    {
        $user->load('groups');

        return response()->json([
            'user' => $user,
            'groups' => $user->groups->map(function ($g) {
                return [
                    'group_id' => $g->id,
                    'group_name' => $g->name,
                    'role' => $g->pivot->role,
                    'status' => $g->pivot->status,
                ];
            }),
        ]);
    }

    /**
     * Блокировка пользователя
     */
    public function block(Request $request, User $user)
    {
        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'Нельзя заблокировать самого себя'], 422);
        }

        $user->update(['account_status' => 'blocked']);

        return response()->json(['message' => 'Пользователь заблокирован']);
    }

    /**
     * Разблокировка пользователя
     */
    public function unblock(Request $request, User $user)
    {
        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'Нельзя разблокировать самого себя'], 422);
        }

        $user->update(['account_status' => 'active']);

        return response()->json(['message' => 'Пользователь активирован']);
    }

    /**
     * Удаление пользователя
     */
    public function destroy(Request $request, User $user)
    {
        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'Нельзя удалить самого себя'], 422);
        }

        $user->delete();

        return response()->json(['message' => 'Пользователь удалён']);
    }

    /**
     * Назначить пользователя админом приложения
     */
    public function promoteToAdmin(Request $request, User $user)
    {
        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'Нельзя назначить самого себя'], 422);
        }

        $user->update(['access_level' => 'admin']);

        return response()->json(['message' => 'Пользователь назначен администратором']);
    }

    /**
     * Снять админа приложения
     */
    public function demoteFromAdmin(Request $request, User $user)
    {
        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'Нельзя снять права у самого себя'], 422);
        }

        $user->update(['access_level' => 'user']);

        return response()->json(['message' => 'Права администратора сняты']);
    }

    /**
     * Блокировка пользователя в конкретной группе
     */
    public function blockInGroup(Request $request, User $user, Group $group)
    {
        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'Нельзя заблокировать самого себя'], 422);
        }

        $exists = $user->groups()->where('groups.id', $group->id)->exists();

        if (!$exists) {
            return response()->json(['message' => 'Пользователь не состоит в группе'], 404);
        }

        $user->groups()->updateExistingPivot($group->id, [
            'status' => 'blocked'
        ]);

        return response()->json(['message' => 'Пользователь заблокирован в группе']);
    }

    /**
     * Разблокировка пользователя в конкретной группе
     */
    public function unblockInGroup(Request $request, User $user, Group $group)
    {
        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'Нельзя разблокировать самого себя'], 422);
        }

        $exists = $user->groups()->where('groups.id', $group->id)->exists();

        if (!$exists) {
            return response()->json(['message' => 'Пользователь не состоит в группе'], 404);
        }

        $user->groups()->updateExistingPivot($group->id, [
            'status' => 'active'
        ]);

        return response()->json(['message' => 'Пользователь разблокирован в группе']);
    }
}
