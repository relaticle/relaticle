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
            self::PRIVATE => __('filament/pages/email-privacy-settings.tiers.private.label'),
            self::METADATA_ONLY => __('filament/pages/email-privacy-settings.tiers.metadata_only.label'),
            self::SUBJECT => __('filament/pages/email-privacy-settings.tiers.subject.label'),
            self::FULL => __('filament/pages/email-privacy-settings.tiers.full.label'),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::PRIVATE => __('filament/pages/email-privacy-settings.tiers.private.description'),
            self::METADATA_ONLY => __('filament/pages/email-privacy-settings.tiers.metadata_only.description'),
            self::SUBJECT => __('filament/pages/email-privacy-settings.tiers.subject.description'),
            self::FULL => __('filament/pages/email-privacy-settings.tiers.full.description'),
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
