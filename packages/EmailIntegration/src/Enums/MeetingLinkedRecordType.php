<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

enum MeetingLinkedRecordType: string implements HasColor, HasIcon, HasLabel
{
    case People = 'people';
    case Company = 'company';
    case Opportunity = 'opportunity';

    public function getLabel(): string
    {
        return match ($this) {
            self::People => __('filament/resources/meeting.linked_record_types.people'),
            self::Company => __('filament/resources/meeting.linked_record_types.companies'),
            self::Opportunity => __('filament/resources/meeting.linked_record_types.opportunities'),
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::People => Heroicon::OutlinedUser,
            self::Company => Heroicon::OutlinedBuildingOffice,
            self::Opportunity => Heroicon::OutlinedCurrencyDollar,
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::People => 'info',
            self::Company => 'warning',
            self::Opportunity => 'success',
        };
    }

    public function linkTargetType(): string
    {
        return match ($this) {
            self::People => 'People',
            self::Company => 'Company',
            self::Opportunity => 'Opportunity',
        };
    }

    public static function tryFromLinkTargetType(string $type): ?self
    {
        return match ($type) {
            'People' => self::People,
            'Company' => self::Company,
            'Opportunity' => self::Opportunity,
            default => null,
        };
    }

    public static function fromLinkTargetType(string $type): self
    {
        return self::tryFromLinkTargetType($type)
            ?? throw new InvalidArgumentException('Unsupported type: '.$type);
    }

    /**
     * @return array<string, string>
     */
    public static function linkTargetTypeOptions(): array
    {
        $options = [];

        foreach (self::cases() as $type) {
            $options[$type->linkTargetType()] = $type->getLabel();
        }

        return $options;
    }
}
