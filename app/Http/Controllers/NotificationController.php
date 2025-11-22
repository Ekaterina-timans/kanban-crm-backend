<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Получить все уведомления пользователя
     */
    public function index(Request $request)
    {
        return response()->json(
            $request->user()->notifications()->orderBy('created_at', 'desc')->get()
        );
    }

    /**
     * Получить только непрочитанные уведомления пользователя
     */
    public function unread(Request $request)
    {
        return response()->json(
            $request->user()->unreadNotifications()->orderBy('created_at', 'desc')->get()
        );
    }

    /**
     * Пометить все уведомления как прочитанные
     */
    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();
        return response()->json(['message' => 'Все уведомления прочитаны!']);
    }
}
