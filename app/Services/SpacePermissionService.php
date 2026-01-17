<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\SpaceUser;

class SpacePermissionService
{
    public function assignDefaultPermissions(SpaceUser $spaceUser): void
    {
        if ($spaceUser->role === 'owner') {
            $spaceUser->permissions()->sync(Permission::pluck('id'));
            return;
        }

        $permissionsByRole = [
            'editor' => [
                'space_read', 'space_edit',
                'column_read', 'column_create', 'column_edit', 'column_delete',
                'task_read', 'task_create', 'task_edit', 'task_delete',
                'comment_read', 'comment_create'
            ],
            'viewer' => [
                'space_read',
                'column_read',
                'task_read',
                'comment_read',
            ],
        ];

        $permissionNames = $permissionsByRole[$spaceUser->role] ?? [];
        $permissionIds = Permission::whereIn('name', $permissionNames)->pluck('id');

        $spaceUser->permissions()->sync($permissionIds);
    }
}
