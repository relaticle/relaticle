<?php

declare(strict_types=1);

namespace Tests;

use App\Features\OnboardSeed;
use App\Features\SetupConversation;
use App\Support\Http\HostResolver;
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

        // A controller's set_time_limit() outlives its request in a test worker and kills
        // whichever test is running once the worker has used that much CPU.
        set_time_limit(0);

        Http::preventStrayRequests();
        $this->app->instance(HostResolver::class, new HostResolver(
            fn (string $host): array => filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : [],
        ));
        Sleep::fake(syncWithCarbon: true);
        Exceptions::fake();

        // InstallCommand shells out to composer, npm and vite. Running those for
        // real cost ~110s and rewrote public/build on every suite run, so any new
        // stray process should fail loudly rather than quietly slow the suite.
        // Only covers the Process facade. Vendor code using Symfony's Process
        // directly (e.g. Shiki) is not intercepted.
        Process::preventStrayProcesses();

        // WorkspaceFactory creates personal workspaces, which fire CreateWorkspaceCustomFields
        // and seed a full demo workspace, ~91 extra rows per workspace, the majority
        // of every insert this suite performs. Tests that exercise onboarding or
        // read demo data re-enable it explicitly.
        Feature::define(OnboardSeed::class, false);

        // Every personal workspace otherwise opens with a setup conversation,
        // which lands in conversation lists, counts and titles across the chat
        // suite. Onboarding tests re-enable it explicitly.
        Feature::define(SetupConversation::class, false);

        // Browser tests drive a real browser and need the built front-end
        // assets (chat.js registers the `chatEditor` Alpine factory, etc.).
        // Stubbing @vite would leave those scripts out and break the page.
        if (! str_contains(static::class, '\\Browser\\')) {
            $this->withoutVite();
        }
    }
}
