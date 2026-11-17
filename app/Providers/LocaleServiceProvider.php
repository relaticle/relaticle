<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Locales;
use Illuminate\Support\ServiceProvider;

final class LocaleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Locales::apply($this->app->getLocale());
    }
}
