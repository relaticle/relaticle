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
            self::All => __('enums/email-folder.all'),
            self::Inbox => __('enums/email-folder.inbox'),
            self::Sent => __('enums/email-folder.sent'),
            self::Drafts => __('enums/email-folder.drafts'),
            self::Archive => __('enums/email-folder.archive'),
        };
    }
}
