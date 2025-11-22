<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\GroupInvitation;

class InviteToGroupMail extends Mailable
{
    use Queueable, SerializesModels;

    public GroupInvitation $invitation;

    /**
     * Create a new message instance.
     */
    public function __construct(GroupInvitation $invitation)
    {
        $this->invitation = $invitation;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invite To Group Mail',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $frontendUrl = env('FRONTEND_URL', 'http://127.0.0.1:3000');
        $url = $frontendUrl . '/auth?invite=' . $this->invitation->token;

        return new Content(
            view: 'emails.invite-to-group',
            with: [
                'group' => $this->invitation->group,
                'url' => $url,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
