<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ReviewFilter: string implements HasIcon, HasLabel
{
    case All = 'all';
    case NeedsReview = 'needs_review';
    case Modified = 'modified';
    case Skipped = 'skipped';

    public function getLabel(): string
    {
        return match ($this) {
            self::All => 'All',
            self::NeedsReview => 'Needs Review',
            self::Modified => 'Modified',
            self::Skipped => 'Skipped',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::NeedsReview => 'heroicon-o-exclamation-triangle',
            default => null,
        };
    }
}
