<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Top-level tabs on the email page. Each renders a nested Livewire component that
 * is also hosted by a standalone page.
 */
enum EmailPageTab: string implements HasIcon, HasLabel
{
    case DRAFTS = 'drafts';
    case OUTBOX = 'outbox';
    case FAILED = 'failed';
    case TEMPLATES = 'templates';

    public function getLabel(): string
    {
        return match ($this) {
            self::DRAFTS => __('filament/pages/email-inbox.tabs.drafts'),
            self::OUTBOX => __('filament/pages/email-inbox.tabs.outbox'),
            self::FAILED => __('filament/pages/email-inbox.tabs.failed'),
            self::TEMPLATES => __('filament/pages/email-inbox.tabs.templates'),
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::DRAFTS => Heroicon::OutlinedPencilSquare,
            self::OUTBOX => Heroicon::OutlinedClock,
            self::FAILED => Heroicon::OutlinedExclamationCircle,
            self::TEMPLATES => Heroicon::OutlinedDocumentDuplicate,
        };
    }

    /**
     * The registered Livewire component rendering this tab's body.
     */
    public function livewireComponent(): string
    {
        return match ($this) {
            self::DRAFTS => 'email-integration.drafts-table',
            self::OUTBOX, self::FAILED => 'email-integration.outbox-table',
            self::TEMPLATES => 'email-integration.templates-table',
        };
    }

    /**
     * @return array<string, bool|EmailStatus>
     */
    public function livewireParameters(): array
    {
        return match ($this) {
            self::OUTBOX => ['includeFailedFilter' => false],
            self::FAILED => ['lockedStatus' => EmailStatus::FAILED],
            default => [],
        };
    }
}
