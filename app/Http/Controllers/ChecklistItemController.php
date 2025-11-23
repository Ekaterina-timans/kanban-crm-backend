<?php

namespace App\Http\Controllers;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChecklistItemController extends Controller
{
    /**
     * Добавить пункт в чек-лист
     */
    public function store(Request $request, Checklist $checklist): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'assignee_id' => 'nullable|exists:users,id',
            'due_date' => 'nullable|date',
        ]);

        $item = $checklist->items()->create($validated);
        $task = $checklist->task;
        $column = $task->column;
        $space = $column->space;

        ActivityLogService::log(
            groupId: $space->group_id,
            userId: $request->user()->id,
            entityType: 'task',
            entityId: $task->id,
            action: 'checklist_item_created',
            changes: [
                'checklist_id' => $checklist->id,
                'item_id' => $item->id,
                'name' => $item->name,
                'assignee_id' => $item->assignee_id,
                'due_date' => $item->due_date,
                'checklist_id' => $checklist->id,
                'checklist_title' => $checklist->title,
                'task_id' => $task->id,
                'task_name' => $task->name,
                'column_id' => $column->id,
                'column_name' => $column->name,
                'space_id' => $space->id,
                'space_name' => $space->name,
            ]
        );

        return response()->json($item, 201);
    }

    /**
     * Обновить пункт
     */
    public function update(Request $request, ChecklistItem $checklist_item): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'assignee_id' => 'nullable|exists:users,id',
            'due_date' => 'nullable|date',
            'completed' => 'boolean',
        ]);

        $task = $checklist_item->checklist->task;
        $column = $task->column;
        $space  = $column->space;
        $old = $checklist_item->only(['name', 'assignee_id', 'due_date', 'completed']);

        $checklist_item->update($validated);

        $new = $checklist_item->only(['name', 'assignee_id', 'due_date', 'completed']);

        ActivityLogService::log(
            groupId: $task->column->space->group_id,
            userId: $request->user()->id,
            entityType: 'task',
            entityId: $task->id,
            action: 'checklist_item_updated',
            changes: [
                'item_id' => $checklist_item->id,
                'old' => $old,
                'new' => $new,
                'checklist_id' => $checklist_item->checklist->id,
                'checklist_title' => $checklist_item->checklist->title,
                'task_id' => $task->id,
                'task_name' => $task->name,
                'column_id' => $column->id,
                'column_name' => $column->name,
                'space_id' => $space->id,
                'space_name' => $space->name,
            ]
        );


        return response()->json($checklist_item);
    }

    /**
     * Удалить пункт
     */
    public function destroy(ChecklistItem $checklist_item): JsonResponse
    {
        $checklist = $checklist_item->checklist;
        $task = $checklist->task;
        $column = $task->column;
        $space = $column->space;

        $snapshot = [
            'item_id' => $checklist_item->id,
            'name' => $checklist_item->name,
            'assignee_id' => $checklist_item->assignee_id,
            'due_date' => $checklist_item->due_date,
            'completed' => $checklist_item->completed,
            'checklist_id' => $checklist->id,
            'checklist_title' => $checklist->title,
            'task_id' => $task->id,
            'task_name' => $task->name,
            'column_id' => $column->id,
            'column_name' => $column->name,
            'space_id' => $space->id,
            'space_name' => $space->name,
        ];

        $checklist_item->delete();

        ActivityLogService::log(
            groupId: $task->column->space->group_id,
            userId: auth()->id(),
            entityType: 'task',
            entityId: $task->id,
            action: 'checklist_item_deleted',
            changes: $snapshot
        );
        
        return response()->json(['message' => 'Item deleted']);
    }
}
