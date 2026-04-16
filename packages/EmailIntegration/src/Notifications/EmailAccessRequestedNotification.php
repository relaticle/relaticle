<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Notifications;

use App\Filament\Pages\EmailAccessRequestsPage;
use App\Filament\Pages\EmailInboxPage;
use App\Models\Team;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;

final class EmailAccessRequestedNotification extends Notification
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
        $email = $this->request->email;
        $team = $email !== null ? Team::query()->find($email->team_id) : null;

        $subject = $email !== null ? ($email->subject ?? '(subject hidden)') : '(subject hidden)';
        $requesterName = $this->request->requester->name;

        $notification = FilamentNotification::make()
            ->title("{$requesterName} requested email access")
            ->body("They requested access to: {$subject}")
            ->warning()
            ->icon('heroicon-o-key');

        if ($team !== null) {
            $actions = [
                Action::make('review')
                    ->label('Review request')
                    ->url(EmailAccessRequestsPage::getUrl(
                        parameters: ['request' => $this->request->getKey()],
                        tenant: $team,
                    ))
                    ->button(),
            ];

            if ($email !== null) {
                $actions[] = Action::make('view')
                    ->label('View email')
                    ->url(EmailInboxPage::getUrl(
                        parameters: ['email' => $email->getKey()],
                        tenant: $team,
                    ))
                    ->color('gray');
            }

            $notification->actions($actions);
        }

        return $notification->getDatabaseMessage();
    }
}
