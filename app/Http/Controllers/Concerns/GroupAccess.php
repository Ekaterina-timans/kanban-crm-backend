<?php

namespace App\Http\Controllers\Concerns;

use App\Models\UserGroup;

trait GroupAccess
{
    protected function assertInGroup(int $userId, int $groupId): void
    {
        $ok = UserGroup::query()
            ->where('user_id', $userId)
            ->where('group_id', $groupId)
            ->exists();

        abort_unless($ok, 403);
    }

    protected function assertGroupAdmin(int $userId, int $groupId): void
    {
        $ok = UserGroup::query()
            ->where('user_id', $userId)
            ->where('group_id', $groupId)
            ->where('role', UserGroup::ROLE_ADMIN)
            ->exists();

        abort_unless($ok, 403);
    }
}
