<?php

declare(strict_types=1);

test('activitylog:clean is scheduled with --force so production runs are not cancelled', function (): void {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('activitylog:clean --force')
        ->assertSuccessful();
});

test('signup records are scheduled for removal after 90 days', function (): void {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('activitylog:clean signup --days=90 --force')
        ->assertSuccessful();
});
