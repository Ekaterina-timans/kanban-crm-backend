<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\GroupInvitation;
use App\Models\User;
use App\Mail\InviteToGroupMail;
use App\Models\Group;
use App\Notifications\GroupInviteNotification;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class GroupInvitationController extends Controller
{
    /**
     * Получить приглашения, которые текущий пользователь отправил в конкретную группу
     */
    public function index(Request $request, $group_id)
    {
        $user = $request->user();

        // Только те приглашения, которые отправил текущий пользователь, в эту группу
        $invitations = GroupInvitation::where('group_id', $group_id)
            ->where('invited_by', $user->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($invitations);
    }
    /**
     * Получить приглашения, которые текущий пользователь получил
     */
    public function userInvitations(Request $request)
    {
        $user = $request->user();
        $email = $user->email;

        // Все приглашения, где пользователь — приглашённый, статус “pending”
        $invitations = GroupInvitation::with('group', 'inviter')
            ->where('email', $email)
            ->where('status', GroupInvitation::STATUS_PENDING)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($invitations);
    }
    /**
     * Пригласить пользователя в группу
     */
    public function invite(Request $request)
    {
        $request->validate([
            'group_id' => 'required|exists:groups,id',
            'email'    => 'required|email|max:100',
            'role'     => 'required|in:admin,member'
        ]);

        // ← ДОБАВЛЕННАЯ ПРОВЕРКА ПРАВ
        $group = Group::findOrFail($request->group_id);
        if (!$group->canInvite($request->user()->id)) {
            return response()->json([
                'message' => 'Вы не можете приглашать участников в эту группу.'
            ], 403);
        }

        $email = mb_strtolower(trim($request->email));

        // Проверить, есть ли уже приглашение
        $existing = GroupInvitation::where('group_id', $request->group_id)
            ->where('email', $request->email)
            ->where('status', GroupInvitation::STATUS_PENDING)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Приглашение уже отправлено!'], 422);
        }

        // === Вот здесь ищем пользователя по email ===
        $user = User::where('email', $request->email)->first();
        if ($user && $user->groups()->where('groups.id', $request->group_id)->exists()) {
            return response()->json(['message' => 'Пользователь уже состоит в этой группе.'], 422);
        }

        $token = Str::uuid();

        $invitation = GroupInvitation::create([
            'group_id'   => $request->group_id,
            'email'      => $request->email,
            'role'       => $request->role,
            'token'      => $token,
            'status'     => GroupInvitation::STATUS_PENDING,
            'invited_by' => $request->user()->id ?? null,
            'expires_at' => now()->addDays(7),
        ]);

        $inviterPivot = $group->users()->where('user_id', $request->user()->id)->first()->pivot;
        if ($inviterPivot->role !== 'admin') {
            ActivityLogService::log(
                groupId: $group->id,
                userId: $request->user()->id,
                entityType: 'participants',
                entityId: $invitation->id,
                action: 'invited',
                changes: [
                    'email' => $email,
                    'role'  => $request->role,
                ]
            );
        }

        // Если пользователь уже есть в системе — можно не отправлять email (или отправить уведомление внутри приложения)
        if ($user) {
            $user->notify(new GroupInviteNotification($invitation));
            Log::info('Notification sent!', ['user_id' => $user->id]);
        } else {
            // Отправляем письмо на почту только если пользователь не зарегистрирован
            Mail::to($request->email)->send(new InviteToGroupMail($invitation));
        }

        return response()->json(['message' => 'Приглашение отправлено!', 'token' => $token]);
    }

    /**
     * Принять приглашение (по токену)
     * Обычно вызывается после регистрации/логина по invite-токену
     */
    public function accept(Request $request)
    {
        $request->validate([
            'token' => 'required|string|exists:group_invitations,token',
        ]);

        $invitation = GroupInvitation::where('token', $request->token)
            ->where('status', GroupInvitation::STATUS_PENDING)
            // ->where('expires_at', '>', now()) // если используешь TTL
            ->first();

        if (!$invitation) {
            return response()->json(['message' => 'Приглашение не найдено или уже использовано.'], 404);
        }

        // Найти пользователя по email или взять авторизованного
        $user = $request->user();
        if (!$user) {
            $user = User::where('email', $invitation->email)->first();
            if (!$user) {
                return response()->json(['message' => 'Пользователь не найден.'], 404);
            }
        }

        Log::info('ACCEPT DEBUG', [
            'user_id' => $user->id,
            'group_id' => $invitation->group_id,
            'already_in_group' => $user->groups()->where('groups.id', $invitation->group_id)->exists(),
        ]);

        // Проверка, не состоит ли уже пользователь в этой группе
        if ($user->groups()->where('groups.id', $invitation->group_id)->exists()) {
            $invitation->status = GroupInvitation::STATUS_ACCEPTED;
            $invitation->save();
            return response()->json(['message' => 'Вы уже в группе.']);
        }

        // Добавить пользователя в группу
        $user->groups()->attach($invitation->group_id, ['role' => $invitation->role]);
        Log::info('Пользователь добавлен в группу', [
            'user_id' => $user->id,
            'group_id' => $invitation->group_id
        ]);

        $invitation->status = GroupInvitation::STATUS_ACCEPTED;
        $invitation->save();

        return response()->json(['message' => 'Вы успешно присоединились к группе!']);
    }

    /**
     * Отклонить приглашение (по токену)
     */
    public function decline(Request $request)
    {
        $request->validate([
            'token' => 'required|string|exists:group_invitations,token',
        ]);

        $invitation = GroupInvitation::where('token', $request->token)
            ->where('status', GroupInvitation::STATUS_PENDING)
            ->first();

        if (!$invitation) {
            return response()->json(['message' => 'Приглашение не найдено или уже обработано.'], 404);
        }

        // Авторизованный пользователь должен совпадать с приглашённым email
        $user = $request->user();
        if (!$user || $user->email !== $invitation->email) {
            return response()->json(['message' => 'Нет доступа к этому приглашению.'], 403);
        }

        $invitation->status = GroupInvitation::STATUS_DECLINED ?? 'declined';
        $invitation->save();

        return response()->json(['message' => 'Приглашение отклонено.']);
    }
}
