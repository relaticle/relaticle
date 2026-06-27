<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasLabel;

enum EmailFolder: string implements HasLabel
{
    case All = 'all';
    case Inbox = 'inbox';
    case Sent = 'sent';
    case Drafts = 'drafts';
    case Archive = 'archive';

    public function getLabel(): string
    {
        return match ($this) {
            self::All => 'All',
            self::Inbox => 'Inbox',
            self::Sent => 'Sent',
            self::Drafts => 'Drafts',
            self::Archive => 'Archive',
        };
    }
}
