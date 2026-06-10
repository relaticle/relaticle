<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Foundation\Testing\WithCachedRoutes;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

abstract class TestCase extends BaseTestCase
{
    use WithCachedConfig;
    use WithCachedRoutes;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake(syncWithCarbon: true);
        Exceptions::fake();

        // Browser tests drive a real browser and need the built front-end
        // assets (chat.js registers the `chatEditor` Alpine factory, etc.).
        // Stubbing @vite would leave those scripts out and break the page.
        if (! str_contains(static::class, '\\Browser\\')) {
            $this->withoutVite();
        }
    }
}
