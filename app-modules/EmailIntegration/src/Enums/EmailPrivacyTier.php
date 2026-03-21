<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasLabel;

enum EmailPrivacyTier: string implements HasLabel
{
    case PRIVATE = 'private';
    case METADATA_ONLY = 'metadata_only';
    case SUBJECT = 'subject';
    case FULL = 'full';

    public function getLabel(): string
    {
        return match ($this) {
            self::PRIVATE => 'Private (only me)',
            self::METADATA_ONLY => 'Metadata only (participants + timestamps)',
            self::SUBJECT => 'Subject line + metadata',
            self::FULL => 'Full access (body, attachments)',
        };
    }
}
