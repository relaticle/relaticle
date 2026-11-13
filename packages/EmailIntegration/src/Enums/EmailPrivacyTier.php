<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum EmailPrivacyTier: string implements HasDescription, HasIcon, HasLabel
{
    case PRIVATE = 'private';
    case METADATA_ONLY = 'metadata_only';
    case SUBJECT = 'subject';
    case FULL = 'full';

    public function showsSubject(): bool
    {
        return in_array($this, [self::SUBJECT, self::FULL], true);
    }

    public function showsBody(): bool
    {
        return $this === self::FULL;
    }

    public function openness(): int
    {
        return match ($this) {
            self::PRIVATE => 0,
            self::METADATA_ONLY => 1,
            self::SUBJECT => 2,
            self::FULL => 3,
        };
    }

    /** @return list<self> */
    public function moreOpenTiers(): array
    {
        return array_values(array_filter(self::cases(), fn (self $tier): bool => $tier->openness() > $this->openness()));
    }

    public static function opennessRanking(): string
    {
        $tiers = self::cases();

        usort($tiers, fn (self $a, self $b): int => $a->openness() <=> $b->openness());

        return '{'.implode(',', array_map(fn (self $tier): string => $tier->value, $tiers)).'}';
    }

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

    public function getIcon(): string
    {
        return match ($this) {
            self::PRIVATE => 'lucide-lock',
            self::METADATA_ONLY => 'lucide-mail',
            self::SUBJECT => 'lucide-mail-open',
            self::FULL => 'lucide-inbox',
        };
    }
}
