<?php

namespace App\Http\Controllers;

use App\Mail\UserBlockedMail;
use App\Models\Group;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class GroupController extends Controller
{
    /**
     * Список всех групп пользователя
     */
    public function index(Request $request)
    {
        // Группы, в которых состоит пользователь + роль
        $groups = $request->user()
            ->groups()
            ->with('creator')
            ->get();

        return response()->json($groups);
    }

    /**
     * Создать новую группу (и сделать пользователя админом этой группы)
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'description' => 'nullable|string',
        ]);

        // Создаем группу
        $group = Group::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'creator_id' => $request->user()->id,
        ]);

        // Добавляем создателя в user_groups как admin
        $group->users()->attach($request->user()->id, ['role' => 'admin']);

        return response()->json($group->load('users', 'creator'), 201);
    }

    public function update(Request $request, Group $group)
    {
        $this->validateAdmin($group, $request->user()->id);

        $data = $request->validate([
            'name' => 'required|string|max:50',
            'description' => 'nullable|string',
            'invite_policy' => 'required|in:admin_only,all'
        ]);

        $group->update($data);

        return response()->json([
            'message' => 'Group updated successfully',
            'group' => $group->fresh('creator', 'users')
        ]);
    }

    public function destroy(Request $request, Group $group)
    {
        $this->validateAdmin($group, $request->user()->id);

        $group->users()->detach();  
        $group->delete();

        return response()->json(['message' => 'Group deleted']);
    }

    /**
     * Присоединиться к группе по ID
     */
    public function join(Request $request, $groupId)
    {
        $group = Group::findOrFail($groupId);
        $user = $request->user();

        // Проверяем, не состоит ли уже пользователь в группе
        if ($group->users()->where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'Already in group'], 409);
        }

        $group->users()->attach($user->id, ['role' => 'member']);

        return response()->json($group->load('users', 'creator'));
    }

    /**
     * Выйти из группы
     */
    public function leave(Request $request, $groupId)
    {
        $group = Group::findOrFail($groupId);
        $user = $request->user();

        // Если пользователь админ и создатель группы, можно добавить проверку на запрет выхода (если хочешь)
        if ($group->creator_id == $user->id) {
            return response()->json(['message' => 'Creator cannot leave the group'], 403);
        }

        $group->users()->detach($user->id);

        return response()->json(['message' => 'Left the group']);
    }

    /**
     * Получить информацию о конкретной группе
     */
    public function show(Request $request, $groupId)
    {
        $group = Group::with('users', 'creator')->findOrFail($groupId);

        // Проверяем, состоит ли пользователь в группе
        if (!$group->users->contains($request->user()->id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($group);
    }

    /**
     * Получить пользователей группы
     */
    public function members(Request $request, Group $group)
    {
        abort_unless(
            $group->users()->whereKey($request->user()->id)->exists(),
            403, 'Forbidden'
        );

        $q = trim((string)$request->query('q', ''));

        $query = $group->users()
            ->withPivot(['role', 'status'])
            ->select('users.id','users.name','users.email','users.avatar')
            ->when($q !== '', function ($qB) use ($q) {
                $qB->where(fn($w) =>
                    $w->where('users.name', 'like', "%{$q}%")
                    ->orWhere('users.email', 'like', "%{$q}%")
                );
            })
            ->orderBy('users.name');

        return response()->json($query->get());
    }

    /**
     * Заблокировать участника
     */
    public function block(Request $request, Group $group, $userId)
    {
        $this->validateAdmin($group, $request->user()->id);

        $group->users()->updateExistingPivot($userId, [
            'status' => 'blocked'
        ]);

        $user = User::find($userId);

        // Отправить уведомление по email
        Mail::to($user->email)->send(new UserBlockedMail($group, $user));

        return response()->json(['message' => 'User blocked']);
    }

    /**
     * Разблокировать
     */
    public function unblock(Request $request, Group $group, $userId)
    {
        $this->validateAdmin($group, $request->user()->id);

        $group->users()->updateExistingPivot($userId, [
            'status' => 'active'
        ]);

        return response()->json(['message' => 'User unblocked']);
    }

     /**
     * Удалить пользователя из группы
     */
    public function remove(Request $request, Group $group, $userId)
    {
        $this->validateAdmin($group, $request->user()->id);

        abort_if($group->creator_id == $userId, 403, 'Creator cannot be removed');

        $group->users()->detach($userId);

        return response()->json(['message' => 'User removed']);
    }

    /**
     * Проверка прав: только админ группы может управлять участниками
     */
    private function validateAdmin(Group $group, $userId)
    {
        $member = $group->users()->find($userId);

        abort_unless(
            $member && $member->pivot->role === 'admin',
            403,
            'Only admin can manage group members'
        );
    }
}
