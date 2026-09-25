<?php

declare(strict_types=1);

namespace App\Support;

use Filament\Facades\Filament;
use Illuminate\Support\Uri;

final readonly class WorkspaceUrlPrefix
{
    public static function get(): string
    {
        $panel = Filament::getPanel('app');
        $domains = $panel->getDomains();

        if ($domains !== []) {
            return reset($domains).'/';
        }

        return Uri::of((string) config('app.url'))->host().'/'.$panel->getPath().'/';
    }
}
