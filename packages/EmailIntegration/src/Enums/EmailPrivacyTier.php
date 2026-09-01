<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum EmailPrivacyTier: string implements HasDescription, HasIcon, HasLabel
{
    case PRIVATE = 'private';
    case METADATA_ONLY = 'metadata_only';
    case SUBJECT = 'subject';
    case FULL = 'full';

    public function getLabel(): string
    {
        return match ($this) {
            self::PRIVATE => 'Private',
            self::METADATA_ONLY => 'Metadata only',
            self::SUBJECT => 'Subject line and metadata',
            self::FULL => 'Full access',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::PRIVATE => 'Nothing is shared. These emails stay visible to you alone',
            self::METADATA_ONLY => 'The email participants and timestamp will be visible to anyone in your workspace',
            self::SUBJECT => "We'll share the subject, participants and timestamp with anyone in your workspace",
            self::FULL => 'Everything is shared with your workspace (including the body, subject line, attachments)',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::PRIVATE => Heroicon::OutlinedLockClosed,
            self::METADATA_ONLY => Heroicon::OutlinedEnvelope,
            self::SUBJECT => Heroicon::OutlinedEnvelopeOpen,
            self::FULL => Heroicon::OutlinedInboxStack,
        };
    }
}
