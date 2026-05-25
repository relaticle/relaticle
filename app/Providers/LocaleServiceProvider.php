<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;

final class LocaleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $locale = $this->app->getLocale();

        Date::setLocale($locale);
        Number::useLocale($locale);
    }
}
