<?php

namespace App\Notifications;

use App\Models\GroupInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class GroupInviteNotification extends Notification
{
    use Queueable;

    public GroupInvitation $invitation;

    /**
     * Create a new notification instance.
     */
    public function __construct(GroupInvitation $invitation)
    {
        $this->invitation = $invitation;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via($notifiable)
    {
        return ['database'];
    }

    public function toDatabase($notifiable)
    {
        return [
            'invitation_id'     => $this->invitation->id,
            'group_id'          => $this->invitation->group_id,
            'group_name'        => optional($this->invitation->group)->name,
            'group_description' => optional($this->invitation->group)->description,
            'inviter_id'        => $this->invitation->invited_by,
            'inviter_name'      => optional($this->invitation->inviter)->name,
            'inviter_email'     => optional($this->invitation->inviter)->email,
            'role'              => $this->invitation->role ?? 'member',
            'token'             => $this->invitation->token,
            'status'            => $this->invitation->status,
            'created_at'        => $this->invitation->created_at,
        ];
    }
}
