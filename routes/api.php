<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AdminGroupController;
use App\Http\Controllers\AdminProfileController;
use App\Http\Controllers\AdminStatisticsController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ChecklistController;
use App\Http\Controllers\ChecklistItemController;
use App\Http\Controllers\ColumnController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\SpaceController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\GroupInvitationController;
use App\Http\Controllers\GroupStatisticsController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationSettingsController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SpaceUserController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserPreferenceController;
use App\Http\Controllers\UserStatisticsController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('web')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout']);
});

Broadcast::routes(['middleware' => ['auth:sanctum']]);

Route::post('/groups/group-invitations/accept', [GroupInvitationController::class, 'accept']);

Route::middleware(['auth:sanctum', 'admin.access'])->prefix('admin')->group(function () {
    // Пользователи
    Route::prefix('users')->group(function () {
        Route::get('/', [AdminUserController::class, 'index']);
        Route::get('/{user}', [AdminUserController::class, 'show']);
        Route::patch('/{user}/block', [AdminUserController::class, 'block']);
        Route::patch('/{user}/unblock', [AdminUserController::class, 'unblock']);
        Route::delete('/{user}', [AdminUserController::class, 'destroy']);
        Route::patch('/{user}/groups/{group}/block', [AdminUserController::class, 'blockInGroup']);
        Route::patch('/{user}/groups/{group}/unblock', [AdminUserController::class, 'unblockInGroup']);
        Route::patch('/{user}/promote', [AdminUserController::class, 'promoteToAdmin']);
        Route::patch('/{user}/demote', [AdminUserController::class, 'demoteFromAdmin']);
    });

    // Группы
    Route::prefix('groups')->group(function () {
        Route::get('/', [AdminGroupController::class, 'index']);
        Route::get('/{group}', [AdminGroupController::class, 'show']);
        Route::delete('/{group}', [AdminGroupController::class, 'destroy']);
    });
    
    // Статистика
    Route::prefix('statistics')->group(function () {
        Route::get('/users-total', [AdminStatisticsController::class, 'usersTotal']);
        Route::get('/users-blocked', [AdminStatisticsController::class, 'usersBlocked']);
        Route::get('/groups-total', [AdminStatisticsController::class, 'groupsTotal']);
        Route::get('/groups-activity', [AdminStatisticsController::class, 'groupsActivity']);
        Route::get('/groups-inactive', [AdminStatisticsController::class, 'inactiveGroups']);
    });

    // Профиль админа
    Route::prefix('profile')->group(function () {
        Route::get('/', [AdminProfileController::class, 'show']);
        Route::post('/', [AdminProfileController::class, 'update']); 
    });
});

Route::middleware('auth:sanctum')->group(function () {
    /** Профиль */
    Route::get('/profile', [UserController::class, 'profile']);
    Route::post('/profile/update', [UserController::class, 'update']);

    /** Пользователь */
    Route::prefix('user')->group(function () {
        Route::get('/', [AuthController::class, 'user']);
        Route::get('/actual', [UserPreferenceController::class, 'show']);
        Route::post('/actual/group', [UserPreferenceController::class, 'setGroup']);
        Route::post('/actual/space', [UserPreferenceController::class, 'setSpace']);
        Route::post('/actual/timezone', [UserPreferenceController::class, 'setTimezone']);
    });

    /** Группы */
    Route::prefix('groups')->group(function () {
        Route::get('/', [GroupController::class, 'index']);
        Route::post('/', [GroupController::class, 'store']);

        // Участие
        Route::post('/{group}/join', [GroupController::class, 'join']);

        Route::get('/group-invitations/user', [GroupInvitationController::class, 'userInvitations']);
        Route::post('/group-invitations/decline', [GroupInvitationController::class, 'decline']);

        Route::middleware('group.access')->group(function () {
            Route::get('/{group}', [GroupController::class, 'show']);
            Route::patch('/{group}', [GroupController::class, 'update']);
            Route::delete('/{group}', [GroupController::class, 'destroy']);

            Route::get('/{group}/members', [GroupController::class, 'members']); // ?q= поиск
            Route::patch('/{group}/members/{user}/block', [GroupController::class, 'block']);
            Route::patch('/{group}/members/{user}/unblock', [GroupController::class, 'unblock']);
            Route::delete('/{group}/members/{user}', [GroupController::class, 'remove']);

            Route::post('/{group}/leave', [GroupController::class, 'leave']);

            /** Приглашения в группу */
            Route::prefix('{group}/group-invitations')->group(function () {
                Route::get('/', [GroupInvitationController::class, 'index']);
                Route::post('/invite', [GroupInvitationController::class, 'invite']);
            });

            /** Чаты */
            Route::prefix('{group}/chats')->group(function () {
                Route::get('/', [ChatController::class, 'index']);   // список чатов
                Route::post('/', [ChatController::class, 'store']);  // создать чат
            });
        });  
    });

    Route::prefix('/statistics')->group(function () {
        Route::get('/personal', [UserStatisticsController::class, 'personal']);

        Route::get('/tasks', [UserStatisticsController::class, 'tasks']);
        Route::get('/statuses', [UserStatisticsController::class, 'statuses']);
        Route::get('/priorities', [UserStatisticsController::class, 'priorities']);
        Route::get('/checklist', [UserStatisticsController::class, 'checklist']);
        Route::get('/hour-activity', [UserStatisticsController::class, 'hourActivity']);
        Route::get('/productivity', [UserStatisticsController::class, 'productivity']);
    });

    Route::prefix('statistics/group')->group(function () {
        Route::get('/top-users', [GroupStatisticsController::class, 'topUsers']);
        Route::get('/tasks-dynamics', [GroupStatisticsController::class, 'tasksDynamics']);
        Route::get('/workload', [GroupStatisticsController::class, 'workload']);
        Route::get('/spaces', [GroupStatisticsController::class, 'spaces']);
        Route::get('/overdue', [GroupStatisticsController::class, 'overdue']);
        Route::get('/team-hours', [GroupStatisticsController::class, 'teamHours']);
    });

    /** Чаты (вне группы) */
    Route::prefix('chats')->group(function () {
        Route::get('/{chat}', [ChatController::class, 'show']);
        Route::post('/{chat}/avatar', [ChatController::class, 'updateAvatar']);
        Route::delete('/{chat}', [ChatController::class, 'destroy']);
        Route::delete('/{chat}/messages', [ChatController::class, 'clearHistory']);

        /** Выйти из группового чата */
        Route::post('/{chat}/leave', [ChatController::class, 'leaveChat'])->name('chats.leave');

        /** Сообщения */
        Route::prefix('{chat}/messages')->group(function () {
            Route::get('/', [MessageController::class, 'index']);
            Route::post('/', [MessageController::class, 'store']);
            Route::delete('/{message}', [MessageController::class, 'destroy']);
        });

        Route::patch('/{chat}/read', [MessageController::class, 'markRead']);

        /** Участники */
        Route::prefix('{chat}/participants')->group(function () {
            Route::get('/', [ChatController::class, 'participants'])->name('chats.participants');
            Route::post('/', [ChatController::class, 'addParticipants'])->name('chats.participants.add');
            Route::patch('/{user}', [ChatController::class, 'updateParticipantRole']);
            Route::delete('/{user}', [ChatController::class, 'removeParticipant'])->name('chats.participants.remove');
        });

        /** Вложения */
        Route::get('/{chat}/attachments', [ChatController::class, 'attachments'])->name('chats.attachments');

        /** Поиск по чатам */
        Route::get('/{chat}/search', [SearchController::class, 'inChat'])->name('search.chat');
    });

    /** Глобальный поиск */
    Route::get('/search', [SearchController::class, 'global'])->name('search.global');

    /** Вложения (файлы) */
    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])->name('attachments.download');

    /** Уведомления */
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread', [NotificationController::class, 'unread']);
        Route::post('/read', [NotificationController::class, 'markAllRead']);
    });

    Route::prefix('notification-settings')->group(function () {
        Route::get('/', [NotificationSettingsController::class, 'show']);
        Route::put('/', [NotificationSettingsController::class, 'update']);
    });

    /** Пространства */
    Route::prefix('spaces')->group(function () {
        Route::get('/', [SpaceController::class, 'index']);
        Route::post('/', [SpaceController::class, 'store']);
        Route::get('/{id}', [SpaceController::class, 'show'])
            ->middleware('space.permission:space_read');
        Route::post('/{id}', [SpaceController::class, 'update'])
            ->middleware('space.permission:space_edit');
        Route::delete('/{id}', [SpaceController::class, 'destroy'])
            ->middleware('space.permission:space_delete');

        /** Пользователи пространства */
        Route::get('/{space}/users', [SpaceUserController::class, 'index'])
            ->middleware('space.permission:space_read');
        Route::post('/{space}/users', [SpaceUserController::class, 'store'])
            ->middleware('space.permission:space_edit');
        Route::get('/{space}/role', [SpaceUserController::class, 'role']);
    });

    Route::prefix('space-users')->group(function () {
        Route::get('/{spaceUser}', [SpaceUserController::class, 'show']);
        Route::put('/{spaceUser}/permissions', [SpaceUserController::class, 'updatePermissions']);
        Route::put('/{spaceUser}/role', [SpaceUserController::class, 'updateRole']);
        Route::delete('/{spaceUser}', [SpaceUserController::class, 'destroy']);
    });

    /** Права */
    Route::get('/permissions', [PermissionController::class, 'index']);

    /** Колонки */
    Route::prefix('columns')->group(function () {
        Route::post('/', [ColumnController::class, 'store'])
            ->middleware('space.permission:column_create');
        Route::put('/{column}', [ColumnController::class, 'update'])
            ->middleware('space.permission:column_edit');
        Route::post('/update-order', [ColumnController::class, 'updateOrder'])
            ->middleware('space.permission:column_edit');
        Route::delete('/{column}', [ColumnController::class, 'destroy'])
            ->middleware('space.permission:column_delete');

        /** Задачи */
        Route::post('/tasks', [TaskController::class, 'store'])
            ->middleware('space.permission:task_create');
        Route::put('/tasks/{task}', [TaskController::class, 'update'])
            ->middleware('space.permission:task_edit');
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])
            ->middleware('space.permission:task_delete');
    });

    Route::get('/tasks/{task}', [TaskController::class, 'show'])
        ->middleware('space.permission:task_read');

    /** Задачи */
    Route::prefix('tasks/{task}')->group(function () {
        Route::put('/rename', [TaskController::class, 'rename'])
            ->middleware('space.permission:task_edit');
        Route::put('/description', [TaskController::class, 'updateDescription'])
            ->middleware('space.permission:task_edit');
        Route::put('/status', [TaskController::class, 'updateStatus'])
            ->middleware('space.permission:task_edit');
        Route::put('/priority', [TaskController::class, 'updatePriority'])
            ->middleware('space.permission:task_edit');
        Route::put('/due-date', [TaskController::class, 'updateDueDate'])
            ->middleware('space.permission:task_edit');
        Route::put('/assignee', [TaskController::class, 'updateAssignee'])
            ->middleware('space.permission:task_edit');

        Route::get('/checklists', [ChecklistController::class, 'index'])
            ->middleware('space.permission:task_read');
        Route::post('/checklists', [ChecklistController::class, 'store'])
            ->middleware('space.permission:task_create');
        Route::get('/comments', [CommentController::class, 'index'])
            ->middleware('space.permission:comment_read');
        Route::post('/comments', [CommentController::class, 'store'])
            ->middleware('space.permission:comment_create');
    });

    /** Работа с отдельным чек-листом */
    Route::apiResource('checklists', ChecklistController::class)
        ->only(['update', 'destroy'])
        ->middleware([
            'update' => 'space.permission:task_edit',
            'destroy' => 'space.permission:task_delete'
        ]);

    /** Пункты чек-листа */
    Route::prefix('checklists/{checklist}')->group(function () {
        Route::post('/items', [ChecklistItemController::class, 'store'])
            ->middleware('space.permission:task_create');
    });

    Route::apiResource('checklist-items', ChecklistItemController::class)
        ->only(['update', 'destroy'])
        ->middleware([
            'update' => 'space.permission:task_edit',
            'destroy' => 'space.permission:task_delete'
        ]);

    /** Комментарии */
    Route::delete('/comments/{comment}', [CommentController::class, 'destroy'])
        ->middleware('space.permission:comment_delete');

    Route::get('/activity-logs', [ActivityLogController::class, 'index']);
});

