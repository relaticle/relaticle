<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Locales;
use Illuminate\Foundation\Events\LocaleUpdated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;

final class LocaleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(LocaleUpdated::class, fn (LocaleUpdated $event) => Number::useLocale(Locales::numberLocale($event->locale)));

        Locales::apply($this->app->getLocale());
    }
}
