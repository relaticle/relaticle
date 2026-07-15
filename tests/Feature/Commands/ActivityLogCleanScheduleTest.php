<?php

declare(strict_types=1);

test('activitylog:clean is scheduled with --force so production runs are not cancelled', function (): void {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('activitylog:clean --force')
        ->assertSuccessful();
});
