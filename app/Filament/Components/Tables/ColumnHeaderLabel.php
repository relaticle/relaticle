<?php

declare(strict_types=1);

namespace App\Filament\Components\Tables;

use BackedEnum;
use Filament\Support\Enums\IconSize;
use Illuminate\Support\HtmlString;

use function Filament\Support\generate_icon_html;

final readonly class ColumnHeaderLabel
{
    public static function make(string $label, string|BackedEnum|null $icon): HtmlString
    {
        $icon = generate_icon_html($icon, size: IconSize::Small)?->toHtml();
        $label = e($label);

        return new HtmlString(
            "<span class=\"fi-ta-header-cell-field inline-flex min-w-0 max-w-full items-center gap-1.5 align-middle\">{$icon}<span class=\"truncate\">{$label}</span></span>"
        );
    }
}
