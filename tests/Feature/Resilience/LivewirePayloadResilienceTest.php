<?php

declare(strict_types=1);

use App\Filament\Notifications\NotificationCollection;
use App\Livewire\FilamentNotifications;
use Filament\Livewire\Notifications;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Features\SupportLifecycleHooks\DirectlyCallingLifecycleHooksNotAllowedException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

it('filters non-array junk out of the notifications livewire payload', function (): void {
    $valid = Notification::make('valid-notification')->title('Hello')->toArray();

    $collection = NotificationCollection::fromLivewire([
        $valid,
        7,
        'junk',
        null,
    ]);

    expect($collection)->toHaveCount(1)
        ->and($collection->first())->toBeInstanceOf(Notification::class)
        ->and($collection->first()->getId())->toBe('valid-notification');
});

it('leaves a notifications payload that is not a list to the parent 419', function (): void {
    NotificationCollection::fromLivewire(42);
})->throws(HttpException::class);

it('resolves the panel notifications component to the resilient subclass', function (): void {
    $panelPath = config('app.app_panel_path', 'app');

    $this->get("/{$panelPath}/login")->assertOk();

    [$name, $class] = resolve('livewire.factory')
        ->resolveComponentNameAndClass(Notifications::class);

    expect($class)->toBe(FilamentNotifications::class);
});

it('mounts the resilient collection on the notifications component', function (): void {
    $instance = Livewire::test(FilamentNotifications::class)->instance();

    expect($instance->notifications)->toBeInstanceOf(NotificationCollection::class);
});

it('survives a full livewire roundtrip with the resilient collection', function (): void {
    Livewire::test(FilamentNotifications::class)
        ->call('removeNotification', 'nonexistent-id')
        ->assertOk();
});

it('responds 419 instead of 500 to a tampered livewire snapshot', function (): void {
    $snapshot = json_encode([
        'data' => [],
        'memo' => ['id' => 'tampered-id', 'name' => 'tampered-component'],
        'checksum' => 'definitely-not-valid',
    ]);

    $this->postJson(route('default-livewire.update'), [
        'components' => [
            ['snapshot' => $snapshot, 'updates' => [], 'calls' => []],
        ],
    ], ['X-Livewire' => 'true'])
        ->assertStatus(419);
});

function postTamperedLivewireUpdate(TestCase $test, array $updates, array $calls = []): TestResponse
{
    $page = $test->get(route('filament.sysadmin.auth.login'))->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', $page, $matches);

    return $test->postJson(route('default-livewire.update'), [
        'components' => [
            ['snapshot' => html_entity_decode($matches[1]), 'updates' => $updates, 'calls' => $calls],
        ],
    ], ['X-Livewire' => 'true']);
}

function postTamperedLockedProperty(TestCase $test): TestResponse
{
    return postTamperedLivewireUpdate($test, ['loadedDeferredSchemas' => ['x' => true]]);
}

it('responds 419 instead of 500 to a tampered locked property', function (): void {
    postTamperedLockedProperty($this)->assertStatus(419);
});

it('does not report a tampered locked property', function (): void {
    Exceptions::fake();

    postTamperedLockedProperty($this);

    Exceptions::assertNotReported(CannotUpdateLockedPropertyException::class);
});

it('responds 419 and does not report an update to an unknown property', function (): void {
    Exceptions::fake();

    postTamperedLivewireUpdate($this, ['noSuchProperty' => 'x'])->assertStatus(419);

    Exceptions::assertNotReported(PublicPropertyNotFoundException::class);
});

it('responds 419 and does not report a direct call to a lifecycle hook', function (): void {
    Exceptions::fake();

    postTamperedLivewireUpdate($this, [], [['method' => 'boot', 'params' => [], 'metadata' => []]])->assertStatus(419);

    Exceptions::assertNotReported(DirectlyCallingLifecycleHooksNotAllowedException::class);
});

it('responds 419 and logs a warning once for a call to a missing method', function (): void {
    Log::spy();

    postTamperedLivewireUpdate($this, [], [['method' => 'noSuchMethod', 'params' => [], 'metadata' => []]])->assertStatus(419);

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => ($context['exception'] ?? null) === MethodNotFoundException::class,
    )->once();
});

it('still reports a listed livewire exception raised outside a livewire update', function (): void {
    Exceptions::fake();
    Route::get('/tamper-probe', fn () => throw new PublicPropertyNotFoundException('probe', 'probe-component'));

    $this->get('/tamper-probe')->assertStatus(500);

    Exceptions::assertReported(PublicPropertyNotFoundException::class);
});

it('logs a tampered update at warning level with the class and the request ip', function (): void {
    Log::spy();

    postTamperedLivewireUpdate($this, ['noSuchProperty' => 'x']);

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => ($context['exception'] ?? null) === PublicPropertyNotFoundException::class
            && ($context['ip'] ?? null) === '127.0.0.1'
            && isset($context['components']),
    )->once();
});

it('reports an exception raised before the console request is bound', function (): void {
    Log::spy();
    $request = resolve('request');
    app()->forgetInstance('request');
    Request::clearResolvedInstance();

    Exceptions::handler()->report(new RuntimeException('console boot error'));

    app()->instance('request', $request);

    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message): bool => $message === 'console boot error',
    )->once();
});
