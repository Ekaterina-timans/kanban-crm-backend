<?php

namespace App\Http\Controllers;

use App\Models\Column;
use App\Models\Space;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ColumnController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'space_id' => 'required|exists:spaces,id',
            'name' => 'required|string|max:50',
            'color' => 'required|string|max:7',
            'position' => 'required|integer'
        ]);

        $space = Space::findOrFail($validated['space_id']);
        $column = Column::create($validated);

        ActivityLogService::log(
            groupId: $space->group_id,
            userId: $request->user()->id,
            entityType: 'column',
            entityId: $column->id,
            action: 'created',
            changes: [
                'name' => $column->name,
                'color' => $column->color,
                'position' => $column->position,
                'space_id' => $space->id,
                'space_name' => $space->name,
            ]
        );

        return response()->json($column, 201);
    }

    public function update(Request $request, $column): JsonResponse
    {
        $column = Column::with('space')->findOrFail($column);
        $oldData = $column->only(['name', 'color', 'position']);
        // Использованием sometimes, что означает, что только переданные поля будут проверяться. Это полезно для частичного обновления
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:50',
            'color' => 'sometimes|required|string|max:7',
            'position' => 'sometimes|required|integer'
        ]);

        $column->update($validated);

        ActivityLogService::log(
            groupId: $column->space->group_id,
            userId: $request->user()->id,
            entityType: 'column',
            entityId: $column->id,
            action: 'updated',
            changes: [
                'old' => $oldData,
                'new' => $column->only(['name', 'color', 'position']),
                'space_id' => $column->space->id,
                'space_name' => $column->space->name,
            ]
        );

        return response()->json($column, 200);
    }

    public function updateOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'columns' => 'required|array',
            'columns.*.id' => 'required|exists:columns,id',
            'columns.*.position' => 'required|integer'
        ]);

        foreach ($validated['columns'] as $col) {
           Column::where('id', $col['id'])->update(['position' => $col['position']]);
        }

        $anyColumn = Column::with('space')->find($validated['columns'][0]['id']);
        ActivityLogService::log(
            groupId: $anyColumn->space->group_id,
            userId: $request->user()->id,
            entityType: 'column',
            entityId: null,
            action: 'order_updated',
            changes: [
                'columns' => $validated['columns'],
                'space_id' => $anyColumn->space->id,
                'space_name' => $anyColumn->space->name,
            ]
        );

        return response()->json(null, 200);
    }

    public function destroy($column): JsonResponse
    {
        $column = Column::with('space')->findOrFail($column);
        $snapshot = $column->only(['id', 'name', 'color', 'position']);
        $snapshot['space_id'] = $column->space->id;
        $snapshot['space_name'] = $column->space->name;
        $groupId = $column->space->group_id;

        $column->delete();

        ActivityLogService::log(
            groupId: $groupId,
            userId: auth()->id(),
            entityType: 'column',
            entityId: $snapshot['id'],
            action: 'deleted',
            changes: $snapshot
        );
        
        return response()->json(null, 200);
    }
}
