<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Notifications;

use App\Enums\Notifications\NotificationReason;
use App\Models\Notification as NotificationRow;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\InboxNotification;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Messages\MailMessage;
use Relaticle\EmailIntegration\Enums\MailboxHealth;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final class MailboxHealthNotification extends InboxNotification
{
    private const string LANG = 'filament/notifications/mailbox-health';

    public function __construct(
        private readonly ConnectedAccount $account,
        private readonly MailboxHealth $health,
        private readonly bool $forAdmins,
    ) {}

    public function workspace(): Workspace
    {
        return $this->account->workspace;
    }

    public function subject(): ConnectedAccount
    {
        return $this->account;
    }

    public function reason(): NotificationReason
    {
        return NotificationReason::System;
    }

    public function groupKey(): string
    {
        return "mailbox-health:{$this->account->getKey()}";
    }

    public function collapsesReadRows(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function facts(): array
    {
        return [
            'health' => $this->health->value,
            'mailbox' => $this->account->email_address,
            'owner' => $this->account->user->name ?? '',
            'for_admins' => $this->forAdmins,
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $facts = $this->facts();
        $url = self::remedyUrl($this->health, $this->forAdmins, $this->account->workspace);

        $mail = (new MailMessage)
            ->subject(__(self::copyKey($facts, 'title')))
            ->greeting(__(self::LANG.'.mail.greeting', ['name' => $notifiable->name]))
            ->line(__(self::copyKey($facts, 'body'), self::replacements($facts)));

        return $url === null ? $mail : $mail->action(__(self::actionKey($this->health)), $url);
    }

    public static function present(NotificationRow $row): FilamentNotification
    {
        $facts = (array) $row->data;
        $health = MailboxHealth::from((string) data_get($facts, 'health'));
        $workspace = $row->workspace;
        $url = $workspace instanceof Workspace ? self::remedyUrl($health, (bool) data_get($facts, 'for_admins'), $workspace) : null;

        $notification = FilamentNotification::make((string) $row->getKey())
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->title(__(self::copyKey($facts, 'title')))
            ->body(__(self::copyKey($facts, 'body'), array_map(e(...), self::replacements($facts))))
            ->actions($url === null ? [] : [
                Action::make('fixMailbox')
                    ->label(__(self::actionKey($health)))
                    ->url($url)
                    ->markAsRead(),
            ]);

        return match ($health) {
            MailboxHealth::ReauthRequired, MailboxHealth::Error => $notification->danger(),
            MailboxHealth::Healthy, MailboxHealth::Stale, MailboxHealth::PausedBilling => $notification->warning(),
        };
    }

    // Only the mailbox owner can grant OAuth consent, so admins get a link only for billing.
    private static function remedyUrl(MailboxHealth $health, bool $forAdmins, Workspace $workspace): ?string
    {
        if ($forAdmins && $health !== MailboxHealth::PausedBilling) {
            return null;
        }

        return $health->remedyUrl($workspace);
    }

    /** @param  array<string, mixed>  $facts */
    private static function copyKey(array $facts, string $part): string
    {
        $audience = data_get($facts, 'for_admins') ? 'admin' : 'owner';

        return self::LANG.'.'.data_get($facts, 'health').".{$audience}.{$part}";
    }

    private static function actionKey(MailboxHealth $health): string
    {
        return self::LANG.'.actions.'.($health === MailboxHealth::PausedBilling ? 'billing' : 'reconnect');
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{mailbox: string, owner: string}
     */
    private static function replacements(array $facts): array
    {
        return [
            'mailbox' => (string) data_get($facts, 'mailbox'),
            'owner' => (string) data_get($facts, 'owner'),
        ];
    }
}
