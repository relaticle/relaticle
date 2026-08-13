<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Top-level tabs on the email page. Every tab except {@see self::EMAILS} renders a
 * nested Livewire component that is also hosted by a standalone page.
 */
enum EmailPageTab: string implements HasIcon, HasLabel
{
    case EMAILS = 'emails';
    case DRAFTS = 'drafts';
    case OUTBOX = 'outbox';
    case TEMPLATES = 'templates';

    public function getLabel(): string
    {
        return match ($this) {
            self::EMAILS => __('filament/pages/email-inbox.tabs.emails'),
            self::DRAFTS => __('filament/pages/email-inbox.tabs.drafts'),
            self::OUTBOX => __('filament/pages/email-inbox.tabs.outbox'),
            self::TEMPLATES => __('filament/pages/email-inbox.tabs.templates'),
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::EMAILS => Heroicon::OutlinedEnvelope,
            self::DRAFTS => Heroicon::OutlinedPencilSquare,
            self::OUTBOX => Heroicon::OutlinedClock,
            self::TEMPLATES => Heroicon::OutlinedDocumentDuplicate,
        };
    }

    /**
     * The registered Livewire component rendering this tab's body, or null for the
     * mail reader, which is the page's own markup.
     */
    public function livewireComponent(): ?string
    {
        return match ($this) {
            self::EMAILS => null,
            self::DRAFTS => 'email-integration.drafts-table',
            self::OUTBOX => 'email-integration.outbox-table',
            self::TEMPLATES => 'email-integration.templates-table',
        };
    }
}
