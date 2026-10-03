<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Support;

use Illuminate\Support\HtmlString;

final readonly class HelpLabel
{
    public static function make(string $label, string $help): HtmlString
    {
        return new HtmlString(view('system-admin::filament.components.help-label', [
            'label' => $label,
            'help' => $help,
        ])->render());
    }
}
