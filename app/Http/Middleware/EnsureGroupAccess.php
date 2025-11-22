<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Group;

class EnsureGroupAccess
{
    public function handle(Request $request, Closure $next)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        /** group параметр приходит из маршрута */
        $group = $request->route('group');

        if (!$group instanceof Group) {
            $groupId = $request->route('group');
            $group = Group::find($groupId);
        }

        // Если группы нет
        if (!$group) {
            abort(404, 'Group not found');
        }

        // Проверка: состоит ли пользователь в группе
        $member = $group->users()->where('user_id', $user->id)->first();

        if (!$member) {
            abort(403, 'You are not a member of this group');
        }

        // Проверка: заблокирован ли
        if ($member->pivot->status === 'blocked') {
            abort(403, 'You are blocked in this group');
        }

        return $next($request);
    }
}
