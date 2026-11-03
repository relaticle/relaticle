<?php

declare(strict_types=1);

namespace App\Filament\Components;

use Illuminate\Support\HtmlString;

final readonly class ImportExportIcon
{
    // Below `sm` the trigger is icon-only, so it shows a kebab; beside the label it keeps the arrows.
    public static function make(): HtmlString
    {
        return new HtmlString(
            svg('heroicon-m-ellipsis-horizontal', 'size-full sm:hidden')->toHtml()
            .svg('heroicon-o-arrows-up-down', 'hidden size-full sm:block')->toHtml(),
        );
    }
}
