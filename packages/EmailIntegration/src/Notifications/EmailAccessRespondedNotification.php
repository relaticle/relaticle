<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;

final class EmailAccessRespondedNotification extends Notification
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
            'status' => $this->request->status,
            'owner_name' => $this->request->owner->name,
        ];
    }
}
