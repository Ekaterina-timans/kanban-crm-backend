<?php

namespace App\Http\Controllers;

use App\Models\Checklist;
use App\Models\Task;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChecklistController extends Controller
{
    /**
     * Получить все чек-листы для задачи
     */
    public function index(Task $task): JsonResponse
    {
        return response()->json($task->checklists()->with('items.assignee')->get());
    }

    /**
     * Создать чек-лист
     */
    public function store(Request $request, Task $task): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:100'
        ]);

        $checklist = $task->checklists()->create($validated);
        $column = $task->column;
        $space = $column->space;

        ActivityLogService::log(
            groupId: $space->group_id,
            userId: $request->user()->id,
            entityType: 'task',
            entityId: $task->id,
            action: 'checklist_created',
            changes: [
                'checklist_id' => $checklist->id,
                'title' => $checklist->title,
                'task_id' => $task->id,
                'task_name' => $task->name,
                'column_id' => $column->id,
                'column_name' => $column->name,
                'space_id' => $space->id,
                'space_name' => $space->name,
            ]
        );

        return response()->json($checklist, 201);
    }

    /** Обновить чек-лист (например, переименовать) */
    public function update(Request $request, Checklist $checklist): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:100',
        ]);

        $task = $checklist->task;
        $column = $task->column;
        $space = $column->space;
        $oldTitle = $checklist->title;

        $checklist->update($validated);

        ActivityLogService::log(
            groupId: $space->group_id,
            userId: $request->user()->id,
            entityType: 'task',
            entityId: $task->id,
            action: 'checklist_updated',
            changes: [
                'checklist_id' => $checklist->id,
                'old_title' => $oldTitle,
                'new_title' => $checklist->title,
                'task_id' => $task->id,
                'task_name' => $task->name,
                'column_id' => $column->id,
                'column_name' => $column->name,
                'space_id' => $space->id,
                'space_name' => $space->name,
            ]
        );

        return response()->json($checklist);
    }

    /** Удалить чек-лист */
    public function destroy(Checklist $checklist): JsonResponse
    {
        $task = $checklist->task;
        $column = $task->column;
        $space = $column->space;

        $snapshot = [
            'checklist_id' => $checklist->id,
            'title' => $checklist->title,
            'task_id' => $checklist->task_id,
            'task_name' => $task->name,
            'column_id' => $column->id,
            'column_name' => $column->name,
            'space_id' => $space->id,
            'space_name' => $space->name,
        ];

        $checklist->delete();

        ActivityLogService::log(
            groupId: $task->column->space->group_id,
            userId: auth()->id(),
            entityType: 'task',
            entityId: $task->id,
            action: 'checklist_deleted',
            changes: $snapshot
        );
        
        return response()->json(['message' => 'Checklist deleted']);
    }
}
