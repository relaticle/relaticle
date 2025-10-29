<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Favicon\Drivers\GoogleHighResDriver;
use App\Services\Favicon\Drivers\HighQualityDriver;
use AshAllenDesign\FaviconFetcher\Facades\Favicon;
use Illuminate\Support\ServiceProvider;

final class FaviconServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Favicon::extend('high-quality', new HighQualityDriver);
        Favicon::extend('google-highres', new GoogleHighResDriver(256));
    }
}
