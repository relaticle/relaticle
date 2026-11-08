<?php

declare(strict_types=1);

namespace App\Enums;

use App\Onboarding\MailboxStep;
use Filament\Support\Contracts\HasLabel;

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
            fn (self $source): bool => $source !== self::Inbox || MailboxStep::canRun(),
        ));
    }

    public static function fromButtonId(string $id): ?self
    {
        return array_find(self::cases(), fn (self $source): bool => $source->buttonId() === $id);
    }

    public function buttonId(): string
    {
        return "source_{$this->value}";
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

    public function seedsSamples(): bool
    {
        return match ($this) {
            self::Memory => true,
            self::Spreadsheet, self::OtherCrm, self::Inbox, self::Assistant => false,
        };
    }

    /**
     * @return list<CreationSource>
     */
    public function arrivalSources(): array
    {
        return match ($this) {
            self::Spreadsheet, self::OtherCrm => [CreationSource::IMPORT],
            self::Inbox => [CreationSource::MAILBOX],
            self::Memory, self::Assistant => [CreationSource::IMPORT, CreationSource::MAILBOX],
        };
    }
}
