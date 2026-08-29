<?php

declare(strict_types=1);

use App\Features\EmailIntegration;
use Illuminate\Console\Scheduling\Schedule;
use Laravel\Pennant\Feature;

it('registers outbox and sync schedules when email integration is active', function (): void {
    Feature::activate(EmailIntegration::class);
    $this->app->forgetInstance(Schedule::class);

    $this->artisan('schedule:list')
        ->expectsOutputToContain('email:dispatch-outbox')
        ->expectsOutputToContain('email:incremental-sync')
        ->expectsOutputToContain('calendar:incremental-sync')
        ->assertSuccessful();
});

it('does not register outbox or sync schedules when email integration is inactive', function (): void {
    config()->set('relaticle.features.email_integration', false);
    Feature::flushCache();
    Feature::deactivate(EmailIntegration::class);
    $this->app->forgetInstance(Schedule::class);

    $this->artisan('schedule:list')
        ->doesntExpectOutputToContain('email:dispatch-outbox')
        ->doesntExpectOutputToContain('email:incremental-sync')
        ->doesntExpectOutputToContain('calendar:incremental-sync')
        ->assertSuccessful();
});
