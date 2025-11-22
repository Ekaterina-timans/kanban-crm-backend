<?php

namespace App\Http\Middleware;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Column;
use App\Models\Comment;
use App\Models\Space;
use App\Models\Task;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SpacePermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        $user = $request->user();

        // 1) Попробовать найти Space напрямую
        $space = $this->resolveSpace($request);

        if (!$user || !$space) {
            return response()->json(['message' => 'Не найдено пространство или пользователь'], 404);
        }

        // Админ группы всегда пропускается
        $isGroupAdmin = DB::table('user_groups')
            ->where('group_id', $space->group_id)
            ->where('user_id', $user->id)
            ->where('role', 'admin')
            ->exists();

        if ($isGroupAdmin) {
            return $next($request);
        }

        // Проверяем участника space
        $spaceUser = $space->spaceUsers()
            ->where('user_id', $user->id)
            ->with('permissions')
            ->first();

        if (!$spaceUser) {
            return response()->json(['message' => 'Вы не состоите в этом пространстве'], 403);
        }

        if ($spaceUser->role === 'owner') {
            return $next($request);
        }

        if (!$spaceUser->permissions->pluck('name')->contains($permission)) {
            return response()->json(['message' => 'Недостаточно прав'], 403);
        }

        return $next($request);
    }

    private function resolveSpace(Request $request): ?Space
    {
        // === 1) Прямой маршрут /spaces/{id} или /spaces/{space} ===
        if ($request->route('space')) {
            $param = $request->route('space');
            return is_numeric($param) ? Space::find($param) : $param;
        }

        if ($request->route('id')) {
            $param = $request->route('id');
            return is_numeric($param) ? Space::find($param) : $param;
        }

        // === 2) Column ===
        if ($request->route('column')) {
            $col = $request->route('column');
            if ($col instanceof Column) return $col->space;
            $col = Column::with('space')->find($col);
            return $col?->space;
        }

        // === 3) Task ===
        if ($request->route('task')) {
            $task = $request->route('task');
            if ($task instanceof Task) return $task->column->space;
            $task = Task::with('column.space')->find($task);
            return $task?->column?->space;
        }

        // === 4) Checklist ===
        if ($request->route('checklist')) {
            $ch = $request->route('checklist');
            if ($ch instanceof Checklist) return $ch->task->column->space;
            $ch = Checklist::with('task.column.space')->find($ch);
            return $ch?->task?->column?->space;
        }

        // === 5) ChecklistItem ===
        if ($request->route('checklist_item')) {
            $item = $request->route('checklist_item');
            if ($item instanceof ChecklistItem) return $item->checklist->task->column->space;
            $item = ChecklistItem::with('checklist.task.column.space')->find($item);
            return $item?->checklist?->task?->column?->space;
        }

        // === 6) Comment ===
        if ($request->route('comment')) {
            $comment = $request->route('comment');
            if ($comment instanceof Comment) return $comment->task->column->space;
            $comment = Comment::with('task.column.space')->find($comment);
            return $comment?->task?->column?->space;
        }

        // === 7) update-order columns ===
        if ($request->input('columns.0.id')) {
            $col = Column::with('space')->find($request->input('columns.0.id'));
            return $col?->space;
        }

        // === 8) store column ===
        if ($request->input('space_id')) {
            return Space::find($request->input('space_id'));
        }

        return null;
    }

}
