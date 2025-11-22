<?php

namespace App\Http\Controllers;

use App\Models\Space;
use App\Models\UserPreference;
use Illuminate\Http\Request;

class UserPreferenceController extends Controller
{
    public function show(Request $request)
    {
        $pref = $request->user()->preference()->first();
        return response()->json($pref);
    }

    public function setGroup(Request $request)
    {
        $data = $request->validate(['group_id' => 'required|exists:groups,id']);

        // Проверяем членство
        $isMember = $request->user()
            ->groups()
            ->where('groups.id', $data['group_id'])
            ->exists();

        if (!$isMember) {
            return response()->json(['message' => 'Нет доступа к этой группе'], 403);
        }

        $pref = UserPreference::firstOrCreate(['user_id' => $request->user()->id]);

        // Если сменили группу — обнуляем space, если он не из новой группы
        if ($pref->current_space_id) {
            $space = Space::find($pref->current_space_id);
            if (!$space || (string)$space->group_id !== (string)$data['group_id']) {
                $pref->current_space_id = null;
            }
        }

        $pref->current_group_id = $data['group_id'];
        $pref->save();

        return response()->json($pref);
    }

    public function setSpace(Request $request)
    {
        $data = $request->validate(['space_id' => 'required|exists:spaces,id']);
        $pref = UserPreference::firstOrCreate(['user_id' => $request->user()->id]);

        if (!$pref->current_group_id) {
            return response()->json(['message' => 'Сначала выберите группу'], 422);
        }

        $space = Space::findOrFail($data['space_id']);
        if ((string)$space->group_id !== (string)$pref->current_group_id) {
            return response()->json(['message' => 'Проект не относится к активной группе'], 422);
        }

        $pref->current_space_id = $space->id;
        $pref->save();

        return response()->json($pref);
    }

    public function setTimezone(Request $request)
    {
        $data = $request->validate([
            'timezone' => 'required|string|max:100'
        ]);

        $pref = UserPreference::firstOrCreate(['user_id' => $request->user()->id]);
        $pref->timezone = $data['timezone'];
        $pref->save();

        return response()->json([
            'message' => 'Часовой пояс обновлён',
            'timezone' => $pref->timezone
        ]);
    }
}
