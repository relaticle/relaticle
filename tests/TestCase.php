<?php

declare(strict_types=1);

namespace Tests;

use App\Features\OnboardSeed;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Foundation\Testing\WithCachedRoutes;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Actions\LogActivityAction;

abstract class TestCase extends BaseTestCase
{
    use WithCachedConfig;
    use WithCachedRoutes;

    /**
     * LogActivityAction keeps its beforeLogging callbacks in a STATIC array that
     * outlives the application. AppServiceProvider appends one per boot, so from
     * the second test in a process the first-registered closure still wins, and
     * it resolves RequestActivityBatch from a container that was flushed at the
     * previous teardown: every activity row gets its own batch_uuid and a single
     * save stops looking like a single save. Clearing here, BEFORE the fresh app
     * boots, leaves exactly this application's closure registered. Clearing in
     * setUp() would be too late and would strip that closure too.
     */
    public function createApplication(): Application
    {
        LogActivityAction::clearBeforeLoggingCallbacks();

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake(syncWithCarbon: true);
        Exceptions::fake();

        // InstallCommand shells out to composer, npm and vite. Running those for
        // real cost ~110s and rewrote public/build on every suite run, so any new
        // stray process should fail loudly rather than quietly slow the suite.
        // Only covers the Process facade — vendor code using Symfony's Process
        // directly (e.g. Shiki) is not intercepted.
        Process::preventStrayProcesses();

        // TeamFactory creates personal teams, which fire CreateTeamCustomFields
        // and seed a full demo workspace — ~91 extra rows per team, the majority
        // of every insert this suite performs. Tests that exercise onboarding or
        // read demo data re-enable it explicitly.
        Feature::define(OnboardSeed::class, false);

        // Browser tests drive a real browser and need the built front-end
        // assets (chat.js registers the `chatEditor` Alpine factory, etc.).
        // Stubbing @vite would leave those scripts out and break the page.
        if (! str_contains(static::class, '\\Browser\\')) {
            $this->withoutVite();
        }
    }
}
