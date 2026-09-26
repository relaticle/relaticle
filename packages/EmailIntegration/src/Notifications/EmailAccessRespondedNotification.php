<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Notifications;

use App\Models\User;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;

final class EmailAccessRespondedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly EmailAccessRequest $request) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $ownerName = $this->request->owner->name;
        $isApproved = $this->request->status === EmailAccessRequestStatus::APPROVED;

        $notification = FilamentNotification::make()
            ->title($isApproved ? 'Email access approved' : 'Email access denied')
            ->body($isApproved
                ? "{$ownerName} approved your request."
                : "{$ownerName} denied your request."
            );

        if ($isApproved) {
            $notification->success()->icon('heroicon-o-check-circle');
        } else {
            $notification->danger()->icon('heroicon-o-x-circle');
        }

        return $notification->getDatabaseMessage();
    }
}
