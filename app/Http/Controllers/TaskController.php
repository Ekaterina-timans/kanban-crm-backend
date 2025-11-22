<?php

namespace App\Http\Controllers;

use App\Models\Column;
use App\Models\Task;
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

        $column = Column::find($validated['column_id']);
        $groupId = $column->space->group_id;

        ActivityLogService::log(
            groupId: $groupId,
            userId: $request->user()->id,
            entityType: 'task',
            entityId: $task->id,
            action: 'created',
            changes: $task->only([
                'name', 'description', 'column_id',
                'assignee_id', 'priority_id', 'status_id', 'due_date'
            ])
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

        $old = $task->column_id;

        $task->column_id = $validated['column_id'];
        $task->save();

        ActivityLogService::log(
            groupId: $task->column->space->group_id,
            userId: $request->user()->id,
            entityType: 'task',
            entityId: $task->id,
            action: 'column_changed',
            changes: [
                'old_column' => $old,
                'new_column' => $validated['column_id']
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

        ActivityLogService::log(
            groupId: $task->column->space->group_id,
            userId: $request->user()->id,
            entityType: 'task',
            entityId: $task->id,
            action: 'renamed',
            changes: [
                'old' => $old,
                'new' => $validated['name']
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

        ActivityLogService::log(
            groupId: $task->column->space->group_id,
            userId: $request->user()->id,
            entityType: 'task',
            entityId: $task->id,
            action: 'description_updated',
            changes: [
                'old' => $old,
                'new' => $validated['description']
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

        ActivityLogService::log(
            groupId: $task->column->space->group_id,
            userId: $request->user()->id,
            entityType: 'task',
            entityId: $task->id,
            action: 'status_updated',
            changes: [
                'old' => $old,
                'new' => $validated['status_id']
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

        ActivityLogService::log(
            groupId: $task->column->space->group_id,
            userId: $request->user()->id,
            entityType: 'task',
            entityId: $task->id,
            action: 'priority_updated',
            changes: [
                'old' => $old,
                'new' => $validated['priority_id']
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

        ActivityLogService::log(
            groupId: $task->column->space->group_id,
            userId: $request->user()->id,
            entityType: 'task',
            entityId: $task->id,
            action: 'due_date_updated',
            changes: [
                'old' => $old,
                'new' => $validated['due_date'] ?? null
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

        ActivityLogService::log(
            groupId: $task->column->space->group_id,
            userId: $request->user()->id,
            entityType: 'task',
            entityId: $task->id,
            action: 'assignee_updated',
            changes: [
                'old' => $old,
                'new' => $validated['assignee_id'] ?? null
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
        $snapshot = $task->only([
            'id', 'name', 'column_id', 'assignee_id',
            'status_id', 'priority_id', 'due_date'
        ]);
        $groupId = $task->column->space->group_id;

        $task->delete();

        ActivityLogService::log(
            groupId: $groupId,
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
