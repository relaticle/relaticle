<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use App\Models\User;
use App\Models\Workspace;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

enum MailboxConnectState: string
{
    case NoProvider = 'no_provider';
    case NotConnected = 'not_connected';
    case SharingUnanswered = 'sharing_unanswered';
    case Connected = 'connected';

    public static function for(User $user, ?Workspace $workspace): self
    {
        $mailbox = $workspace instanceof Workspace
            ? ConnectedAccount::query()->newestConnectedFor($user, $workspace)->first()
            : null;

        return match (true) {
            ! array_any(EmailProvider::cases(), fn (EmailProvider $provider): bool => $provider->isConfigured()) => self::NoProvider,
            ! $mailbox instanceof ConnectedAccount => self::NotConnected,
            ! $mailbox->hasAnsweredSharing() => self::SharingUnanswered,
            default => self::Connected,
        };
    }

    public function showsCard(): bool
    {
        return $this !== self::Connected;
    }

    public function heading(): string
    {
        return __("filament/pages/email-accounts.connect_card.{$this->value}.heading");
    }

    public function description(): string
    {
        return __("filament/pages/email-accounts.connect_card.{$this->value}.description");
    }

    public function icon(): string
    {
        return match ($this) {
            self::NoProvider => 'lucide-wrench',
            self::NotConnected => 'lucide-mail',
            self::SharingUnanswered => 'lucide-users',
            self::Connected => 'lucide-mail-check',
        };
    }
}
