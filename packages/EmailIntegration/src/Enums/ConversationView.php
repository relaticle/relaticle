<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasLabel;

enum ConversationView: string implements HasLabel
{
    public const int AWAITING_REPLY_BUSINESS_DAYS = 3;

    case NeedsReply = 'needs_reply';
    case AwaitingReply = 'awaiting_reply';
    case All = 'all';
    case Unlinked = 'unlinked';
    case Automated = 'automated';

    public function getLabel(): string
    {
        return match ($this) {
            self::NeedsReply => __('filament/pages/email-inbox.views.needs_reply'),
            self::AwaitingReply => __('filament/pages/email-inbox.views.awaiting_reply'),
            self::All => __('filament/pages/email-inbox.views.all'),
            self::Unlinked => __('filament/pages/email-inbox.views.unlinked'),
            self::Automated => __('filament/pages/email-inbox.views.automated'),
        };
    }

    public function isPrimary(): bool
    {
        return match ($this) {
            self::NeedsReply, self::AwaitingReply, self::All, self::Unlinked => true,
            self::Automated => false,
        };
    }

    public function requiresOwnCopy(): bool
    {
        return match ($this) {
            self::NeedsReply, self::AwaitingReply, self::Unlinked => true,
            self::All, self::Automated => false,
        };
    }

    public function includesAutomated(): bool
    {
        return match ($this) {
            self::Automated => true,
            self::NeedsReply, self::AwaitingReply, self::All, self::Unlinked => false,
        };
    }
}
