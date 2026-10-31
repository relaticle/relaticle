<?php

declare(strict_types=1);

use App\Health\TorExitListCheck;
use App\Support\Http\TorExitNodes;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;
use Spatie\Health\Enums\Status;

mutates(TorExitListCheck::class, TorExitNodes::class);

function turnTorGateOn(): void
{
    config(['relaticle.features.signup_challenge' => true]);
    Feature::flushCache();
}

function fetchTorExitList(): void
{
    $relays = collect(range(1, 600))->map(fn (int $relay): string => long2ip(ip2long('10.0.0.0') + $relay));

    Http::fake([TorExitNodes::SOURCE_URL => Http::response($relays->implode("\n"))]);

    resolve(TorExitNodes::class)->refresh();
}

it('fails when the tor gate is on and the exit list was never fetched', function (): void {
    turnTorGateOn();

    $result = TorExitListCheck::new()->run();

    expect($result->status)->toBe(Status::failed())
        ->and($result->notificationMessage)->toBe('The Tor exit list has never been fetched, so sign-up accepts Tor exits.');
});

it('passes when the exit list was refreshed within three hours', function (): void {
    turnTorGateOn();
    fetchTorExitList();

    $this->travel(2)->hours();

    expect(TorExitListCheck::new()->run()->status)->toBe(Status::ok());
});

it('fails when the exit list is older than three hours', function (): void {
    turnTorGateOn();
    fetchTorExitList();

    $this->travel(4)->hours();

    $result = TorExitListCheck::new()->run();

    expect($result->status)->toBe(Status::failed())
        ->and($result->notificationMessage)->toBe('The Tor exit list was last refreshed 4 hours ago.');
});

it('passes while the tor gate is off', function (): void {
    expect(TorExitListCheck::new()->run()->status)->toBe(Status::ok());
});
