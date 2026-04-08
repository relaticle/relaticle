<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;

final class EmailAccessRequestedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly EmailAccessRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'request_id' => $this->request->getKey(),
            'requester_name' => $this->request->requester->name,
            'tier_requested' => $this->request->tier_requested,
            'email_subject' => $this->request->email?->subject ?? '(subject hidden)',
        ];
    }
}
