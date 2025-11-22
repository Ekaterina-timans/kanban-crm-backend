<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class ActivityLogService
{
    /**
     * Универсальный метод логирования действия.
     *
     * @param int         $groupId
     * @param int|null    $userId
     * @param string      $entityType  Например: 'space', 'task', 'participant'
     * @param int|null    $entityId
     * @param string      $action      Например: 'created', 'updated', 'deleted'
     * @param array|null  $changes     Любые данные о изменениях (старое/новое и т.п.)
     * @return ActivityLog
     */
    public static function log(
        int $groupId,
        ?int $userId,
        string $entityType,
        ?int $entityId,
        string $action,
        ?array $changes = null
    ): ActivityLog {
        // Если userId не передали — берём из Auth
        if ($userId === null) {
            $userId = Auth::id();
        }

        return ActivityLog::create([
            'group_id'    => $groupId,
            'user_id'     => $userId,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'action'      => $action,
            'changes'     => $changes,
        ]);
    }
}
