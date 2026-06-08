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

use App\Models\User;
use Illuminate\Contracts\Broadcasting\Broadcaster as BroadcasterContract;
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

/**
 * Invoke the chat conversation broadcast channel authorization callback.
 *
 * The conversation channel is registered on boot; if the broadcaster has not yet
 * loaded it (rare worker-restart scenarios in tests), we re-require the package
 * channel routes file once and retry.
 */
function chatChannelAuth(User $user, string $conversationId): bool
{
    $broadcaster = app(BroadcasterContract::class);
    $reflection = new ReflectionClass($broadcaster);
    $prop = $reflection->getProperty('channels');
    $prop->setAccessible(true);
    $channels = $prop->getValue($broadcaster);

    $callback = $channels['chat.conversation.{conversationId}'] ?? null;

    if ($callback === null) {
        require __DIR__.'/../packages/Chat/routes/channels.php';

        return chatChannelAuth($user, $conversationId);
    }

    return (bool) $callback($user, $conversationId);
}

/**
 * Invoke the App.Models.User.{id} broadcast channel authorization callback.
 *
 * Mirrors chatChannelAuth — retrieves the registered closure via reflection
 * and invokes it directly, so tests exercise the real production callback.
 */
function userChannelAuth(User $user, string $id): bool
{
    $broadcaster = app(BroadcasterContract::class);
    $reflection = new ReflectionClass($broadcaster);
    $prop = $reflection->getProperty('channels');
    $prop->setAccessible(true);
    $channels = $prop->getValue($broadcaster);

    $callback = $channels['App.Models.User.{id}'] ?? null;

    if ($callback === null) {
        require base_path('routes/channels.php');

        return userChannelAuth($user, $id);
    }

    return (bool) $callback($user, $id);
}
