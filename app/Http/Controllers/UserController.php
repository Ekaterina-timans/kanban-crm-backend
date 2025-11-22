<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    /**
     * Возвращает данные текущего пользователя (только профиль)
     */
    public function profile(Request $request)
    {
        return response()->json([
            'user' => $request->user()->only(['id', 'name', 'email', 'avatar', 'created_at']),
        ]);
    }

    /**
     * Обновление профиля
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|unique:users,email,' . $user->id,
            'password' => 'nullable|min:6',
            'avatar' => 'nullable|image|max:2048',
        ]);

        // Загрузка нового аватара
        if ($request->hasFile('avatar')) {
            // Удаляем старый, если был
            if ($user->avatar && str_contains($user->avatar, '/storage/')) {
                $oldPath = str_replace('/storage/', '', $user->avatar);
                Storage::disk('public')->delete($oldPath);
            }

            $path = $request->file('avatar')->store('avatars', 'public');
            $data['avatar'] = Storage::url($path);
        }

        // Хэшируем пароль, если задан
        if (!empty($data['password'])) {
            $data['password'] = bcrypt($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        return response()->json([
            'message' => 'Профиль обновлён успешно',
            'user' => $user->only(['id', 'name', 'email', 'avatar', 'created_at']),
        ]);
    }
}
