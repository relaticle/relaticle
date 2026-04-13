<?php

declare(strict_types=1);

/**
 * Test Suite Architecture (Testing Trophy)
 *
 * Layer 0: Static Analysis (PHPStan, Pint, Rector, Type Coverage 100%)
 * Layer 1: Architecture Tests (tests/Arch/)
 * Layer 2: Smoke Tests (tests/Smoke/) -- HTTP-level route smoke
 * Layer 3: Workflow Tests (tests/Feature/) -- bulk of suite
 * Layer 4: Browser Tests (tests/Browser/) -- critical paths only
 *
 * Conventions: see CLAUDE.md -> Testing section
 */

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Pest\Browser\Playwright\Playwright;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature', 'Smoke', 'Browser');

if (class_exists(Playwright::class)) {
    Playwright::setTimeout(30_000);
}

/**
 * Livewire testing helper - replacement for pest-plugin-livewire.
 *
 * @param  class-string  $component
 * @param  array<string, mixed>  $params
 */
function livewire(string $component, array $params = []): Testable
{
    return Livewire::test($component, $params);
}
