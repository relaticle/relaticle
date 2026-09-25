<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Override;

/**
 * @mixin Page
 */
trait UsesRecordIndexLayout
{
    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'fi-record-index-page',
        ];
    }

    #[Override]
    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }
}
