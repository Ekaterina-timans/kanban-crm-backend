<?php

namespace App\Http\Controllers;

use App\Models\NotificationSetting;
use Illuminate\Http\Request;

class NotificationSettingsController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        $settings = NotificationSetting::firstOrCreate([
            'user_id' => $user->id,
        ]);

        return response()->json($settings);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            // In-app
            'inapp_chat_messages' => 'sometimes|boolean',
            'inapp_mentions' => 'sometimes|boolean',
            'inapp_task_assigned' => 'sometimes|boolean',
            'inapp_comments_on_my_tasks' => 'sometimes|boolean',
            'inapp_comments_on_assigned_tasks' => 'sometimes|boolean',
            'inapp_deadline_reminders' => 'sometimes|boolean',

            // Email
            'email_chat_messages' => 'sometimes|boolean',
            'email_task_assigned' => 'sometimes|boolean',
            'email_comments_on_my_tasks' => 'sometimes|boolean',
            'email_comments_on_assigned_tasks' => 'sometimes|boolean',
            'email_deadline_reminders' => 'sometimes|boolean',

            // Deadline extras
            'deadline_days_before' => 'sometimes|integer|min:0|max:30',
            'deadline_notify_time' => ['sometimes', 'regex:/^\d{2}:\d{2}$/'],
        ]);

        $settings = NotificationSetting::firstOrCreate(['user_id' => $user->id]);
        $settings->fill($validated);
        $settings->save();

        return response()->json([
            'message' => 'Настройки уведомлений обновлены',
            'settings' => $settings,
        ]);
    }
}
