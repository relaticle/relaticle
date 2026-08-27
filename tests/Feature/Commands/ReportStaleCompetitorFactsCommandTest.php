<?php

declare(strict_types=1);

use App\Support\CompetitorFacts;

it('exposes dated facts for every competitor used on public pages', function (): void {
    $facts = CompetitorFacts::all();

    expect($facts)->toHaveKeys(['relaticle', 'twenty', 'espocrm', 'attio', 'hubspot'])
        ->and($facts['twenty']['verified'])->toMatch('/^\d{4}-\d{2}-\d{2}$/')
        ->and($facts['relaticle']['pricing'])->toContain('$24');
});

it('reports stale facts older than 90 days', function (): void {
    $this->artisan('gtm:stale-facts')
        ->assertSuccessful();
});
