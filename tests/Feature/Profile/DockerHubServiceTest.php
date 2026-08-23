<?php

declare(strict_types=1);

use App\Services\DockerHubService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

mutates(DockerHubService::class);

beforeEach(function (): void {
    Cache::forget('dockerhub_pulls_manukminasyan_relaticle');
    Cache::forget('dockerhub_pulls_manukminasyan_relaticle_last_good');
});

it('rounds the pull count down to the nearest thousand', function (): void {
    Http::fake(['hub.docker.com/*' => Http::response(['pull_count' => 21987])]);

    expect((new DockerHubService)->getFormattedPullCount())->toBe('21,000+');
});

it('returns null below a thousand pulls so the homepage hides the line', function (): void {
    Http::fake(['hub.docker.com/*' => Http::response(['pull_count' => 412])]);

    expect((new DockerHubService)->getFormattedPullCount())->toBeNull();
});

it('returns null when Docker Hub is unreachable', function (): void {
    Http::fake(['hub.docker.com/*' => Http::response(null, 500)]);

    expect((new DockerHubService)->getFormattedPullCount())->toBeNull();
});

it('caches the pull count', function (): void {
    Http::fake(['hub.docker.com/*' => Http::response(['pull_count' => 5000])]);
    $service = new DockerHubService;

    $service->getPullCount();
    $service->getPullCount();

    Http::assertSentCount(1);
});

it('keeps the last good pull count when Docker Hub fails on the next fetch', function (): void {
    Http::fake(['hub.docker.com/*' => Http::sequence()
        ->push(['pull_count' => 21030])
        ->push(null, 500)]);
    $service = new DockerHubService;

    expect($service->getFormattedPullCount())->toBe('21,000+');

    Cache::forget('dockerhub_pulls_manukminasyan_relaticle');

    expect($service->getFormattedPullCount())->toBe('21,000+');
});
