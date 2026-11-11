<?php

declare(strict_types=1);

namespace App\Filament\Tables;

use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Support\Enums\IconPosition;

final class BulkBar
{
    /**
     * @param  list<BulkAction>  $actions
     * @param  list<BulkAction>  $more
     */
    public static function make(array $actions, array $more): BulkActionGroup
    {
        return BulkActionGroup::make([
            ...$actions,
            BulkActionGroup::make($more)
                ->label(__('filament/panel.tables.bulk_bar.more'))
                ->button()
                ->labeledFrom()
                ->color('gray')
                ->icon('lucide-chevron-down')
                ->iconPosition(IconPosition::After)
                ->dropdownPlacement('top-end'),
        ])
            ->buttonGroup()
            ->view('filament.app.tables.bulk-bar');
    }
}
