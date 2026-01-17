<?php

namespace App\Http\Controllers;

use App\Models\Column;
use App\Models\Task;
use App\Notifications\TaskAssignedEmailNotification;
use App\Notifications\TaskAssignedInAppNotification;
use App\Services\ActivityLogService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'column_id' => 'required|exists:columns,id',
            'name' => 'required|string|max:50',
            'description' => 'nullable|string',
            'assignee_id' => 'nullable|exists:users,id',
            'status_id' => 'required|exists:statuses,id',
            'priority_id' => 'required|exists:priorities,id',
            'due_date' => 'nullable|date'
        ]);

        $validated['author_id'] = auth()->id();

        if (isset($validated['due_date'])) {
        // Если время не указано, устанавливаем его на полночь
            $dueDate = \Carbon\Carbon::parse($validated['due_date']);
            if ($dueDate->format('H:i:s') === '00:00:00') {
                $validated['due_date'] = $dueDate->startOfDay();
            }
        } else {
            $validated['due_date'] = null;
        }

        $task = Task::create($validated);

        if (!empty($task->assignee_id) && (int)$task->assignee_id !== (int)auth()->id()) {
            $assignee = \App\Models\User::query()->find($task->assignee_id);

            if ($assignee) {
                $st = \App\Models\NotificationSetting::query()
                    ->where('user_id', $assignee->id)
                    ->first();

                if ((bool)($st?->inapp_task_assigned)) {
                    $assignee->notify(new TaskAssignedInAppNotification($task));
                }

                if ((bool)($st?->email_task_assigned)) {
                    $assignee->notify(new TaskAssignedEmailNotification($task));
                }
            }
        }

        $column = Column::with('space')->findOrFail($validated['column_id']);
        $space = $column->space;

        ActivityLogService::log(
            groupId: $space->group_id,
            userId: $request->user()->id,
            entityType: 'task',
            entityId: $task->id,
            action: 'created',
            changes: [
                'name' => $task->name,
                'description' => $task->description,
                'assignee_id' => $task->assignee_id,
                'status_id' => $task->status_id,
                'priority_id' => $task->priority_id,
                'due_date' => $task->due_date,
                'column_id' => $column->id,
                'column_name' => $column->name,
                'space_id' => $space->id,
                'space_name' => $space->name,
            ]
        );

        return response()->json($task, 201);
    }

    public function show(Task $task): JsonResponse
    {
        $task->load([
            'author:id,name,email',
            'assignee:id,name,email,avatar',
            'status:id,name',
            'priority:id,name',
            'checklists.items.assignee:id,name,email',
            'comments.user:id,name,email,avatar',
        ]);

        return response()->json($task);
    }
    /** Обновление колонки */
    public function update(Request $request, Task $task): JsonResponse
    {
        $validated = $request->validate([
            'column_id' => 'required|exists:columns,id',
        ]);

        $oldColumn = $task->column;
        $newColumn = Column::with('space')->findOrFail($validated['column_id']);

        $task->column_id = $validated['column_id'];
        $task->save();

        ActivityLogService::log(
            groupId: $newColumn->space->group_id,
            userId: $request->user()->id,
            entityType: 'task',
            entityId: $task->id,
            action: 'column_changed',
            changes: [
                'old_column_id' => $oldColumn->id,
                'old_column_name' => $oldColumn->name,

                'new_column_id' => $newColumn->id,
                'new_column_name' => $newColumn->name,

                'space_id' => $newColumn->space->id,
                'space_name' => $newColumn->space->name,
                'task_name' => $task->name,
            ]
        );

        return response()->json(['task' => $task]);
    }
    /** Изменение названия задачи */
    public function rename(Request $request, Task $task): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255'
        ]);

        $old = $task->name;
        $task->update(['name' => $validated['name']]);

        $column = $task->column;
        $space = $column->space;

        ActivityLogService::log(
            groupId: $space->group_id,
            userId: $request->user()->id,
            entityType: 'task',
            entityId: $task->id,
            action: 'renamed',
            changes: [
                'old' => $old,
                'new' => $validated['name'],
                'column_name' => $column->name,
                'space_id' => $space->id,
                'space_name' => $space->name,
            ]
        );

        return response()->json([
            'message' => 'Название задачи обновлено',
            'task' => $task
        ]);
    }

    /** Обновление описания */
    public function updateDescription(Request $request, Task $task): JsonResponse
    {
        $validated = $request->validate([
            'description' => 'nullable|string|max:1000'
        ]);

        $old = $task->description;
        $task->update(['description' => $validated['description']]);

        $column = $task->column;
        $space = $column->space;

        ActivityLogService::log(
            groupId: $space->group_id,
            userId: $request->user()->id,
            entityType: 'task',
            entityId: $task->id,
            action: 'description_updated',
            changes: [
                'old' => $old,
                'new' => $validated['description'],
                'column_name' => $column->name,
                'space_id' => $space->id,
                'space_name' => $space->name,
                'task_name' => $task->name,
            ]
        );

        return response()->json([
            'message' => 'Описание обновлено',
            'task' => $task
        ]);
    }

    /** Обновление статуса */
    public function updateStatus(Request $request, Task $task): JsonResponse
    {
        $validated = $request->validate([
            'status_id' => 'required|exists:statuses,id'
        ]);

        $old = $task->status_id;
        $task->update(['status_id' => $validated['status_id']]);

        $column = $task->column;
        $space = $column->space;

        ActivityLogService::log(
            groupId: $space->group_id,
            userId: $request->user()->id,
            entityType: 'task',
            entityId: $task->id,
            action: 'status_updated',
            changes: [
                'old' => $old,
                'new' => $validated['status_id'],
                'column_name' => $column->name,
                'space_id' => $space->id,
                'space_name' => $space->name,
                'task_name' => $task->name,
            ]
        );

        return response()->json([
            'message' => 'Статус задачи обновлён',
            'task' => $task
        ]);
    }

    /** Обновление приоритета */
    public function updatePriority(Request $request, Task $task): JsonResponse
    {
        $validated = $request->validate([
            'priority_id' => 'required|exists:priorities,id'
        ]);

        $old = $task->priority_id;
        $task->update(['priority_id' => $validated['priority_id']]);

        $column = $task->column;
        $space = $column->space;

        ActivityLogService::log(
            groupId: $space->group_id,
            userId: $request->user()->id,
            entityType: 'task',
            entityId: $task->id,
            action: 'priority_updated',
            changes: [
                'old' => $old,
                'new' => $validated['priority_id'],
                'column_name' => $column->name,
                'space_id' => $space->id,
                'space_name' => $space->name,
                'task_name' => $task->name,
            ]
        );

        return response()->json([
            'message' => 'Приоритет задачи обновлён',
            'task' => $task
        ]);
    }

    /** Обновление срока выполнения */
    public function updateDueDate(Request $request, Task $task): JsonResponse
    {
        $validated = $request->validate([
            'due_date' => 'nullable|date'
        ]);

        $old = $task->due_date;

        if (isset($validated['due_date'])) {
            $dueDate = Carbon::parse($validated['due_date']);
            if ($dueDate->format('H:i:s') === '00:00:00') {
                $validated['due_date'] = $dueDate->startOfDay();
            }
        } else {
            $validated['due_date'] = null;
        }

        $task->update(['due_date' => $validated['due_date']]);

        $column = $task->column;
        $space = $column->space;

        ActivityLogService::log(
            groupId: $space->group_id,
            userId: $request->user()->id,
            entityType: 'task',
            entityId: $task->id,
            action: 'due_date_updated',
            changes: [
                'old' => $old,
                'new' => $validated['due_date'] ?? null,
                'column_name' => $column->name,
                'space_id' => $space->id,
                'space_name' => $space->name,
                'task_name' => $task->name,
            ]
        );

        return response()->json([
            'message' => 'Срок выполнения обновлён',
            'task' => $task
        ]);
    }

    /** Обновление ответственного */
    public function updateAssignee(Request $request, Task $task): JsonResponse
    {
        $validated = $request->validate([
            'assignee_id' => 'nullable|exists:users,id'
        ]);

        $old = $task->assignee_id;
        $task->update([
            'assignee_id' => $validated['assignee_id'] ?? null
        ]);

        $newAssigneeId = $validated['assignee_id'] ?? null;

        // если назначили на кого-то (не null) и это не тот, кто делал действие
        if (!empty($newAssigneeId) && (int)$newAssigneeId !== (int)$request->user()->id) {
            // важно: слать только если реально изменилось
            if ((int)$old !== (int)$newAssigneeId) {
                $assignee = \App\Models\User::query()->find($newAssigneeId);

                if ($assignee) {
                    $st = \App\Models\NotificationSetting::query()
                        ->where('user_id', $assignee->id)
                        ->first();

                    if ((bool)($st?->inapp_task_assigned)) {
                        $assignee->notify(new TaskAssignedInAppNotification($task));
                    }

                    if ((bool)($st?->email_task_assigned)) {
                        $assignee->notify(new TaskAssignedEmailNotification($task));
                    }
                }
            }
        }

        $column = $task->column;
        $space = $column->space;

        ActivityLogService::log(
            groupId: $space->group_id,
            userId: $request->user()->id,
            entityType: 'task',
            entityId: $task->id,
            action: 'assignee_updated',
            changes: [
                'old' => $old,
                'new' => $validated['assignee_id'] ?? null,
                'column_name' => $column->name,
                'space_id' => $space->id,
                'space_name' => $space->name,
                'task_name' => $task->name,
            ]
        );

        $task->load('assignee:id,name,email,avatar');

        return response()->json([
            'message' => 'Ответственный обновлён',
            'task' => $task
        ]);
    }

    public function destroy(Task $task): JsonResponse
    {
        $column = $task->column;
        $space = $column->space;

        $snapshot = $task->only([
            'id', 'name', 'column_id', 'assignee_id',
            'status_id', 'priority_id', 'due_date'
        ]);
        $snapshot['column_name'] = $column->name;
        $snapshot['space_id'] = $space->id;
        $snapshot['space_name'] = $space->name;

        $task->delete();

        ActivityLogService::log(
            groupId: $space->group_id,
            userId: auth()->id(),
            entityType: 'task',
            entityId: $snapshot['id'],
            action: 'deleted',
            changes: $snapshot
        );

        return response()->json([
            'message' => 'Задача успешно удалена'
        ], 200);
    }
}
