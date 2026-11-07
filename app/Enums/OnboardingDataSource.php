<?php

declare(strict_types=1);

namespace App\Enums;

use App\Features\EmailIntegration;
use Filament\Support\Contracts\HasLabel;
use Laravel\Pennant\Feature;

enum OnboardingDataSource: string implements HasLabel
{
    case Spreadsheet = 'spreadsheet';
    case OtherCrm = 'other_crm';
    case Inbox = 'inbox';
    case Memory = 'memory';
    case Assistant = 'assistant';

    /**
     * @return list<self>
     */
    public static function offered(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $source): bool => $source !== self::Inbox || Feature::active(EmailIntegration::class),
        ));
    }

    public function getLabel(): string
    {
        return __("filament/pages/workspaces.setup_workspace.source.options.{$this->value}.label");
    }

    public function getDescription(): string
    {
        return __("filament/pages/workspaces.setup_workspace.source.options.{$this->value}.description");
    }

    public function isFile(): bool
    {
        return match ($this) {
            self::Spreadsheet, self::OtherCrm => true,
            self::Inbox, self::Memory, self::Assistant => false,
        };
    }
}
