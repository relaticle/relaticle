<?php

declare(strict_types=1);

use App\Services\GitHubService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Create a new instance of the service for each test
    $this->service = new GitHubService;

    // Clear any cached values before each test
    Cache::forget('github_stars_Relaticle_relaticle');
});

it('gets stars count from GitHub API', function () {
    // Mock HTTP response
    Http::fake([
        'api.github.com/repos/Relaticle/relaticle' => Http::response([
            'stargazers_count' => 125,
        ], 200),
    ]);

    // Call the service
    $result = $this->service->getStarsCount('Relaticle', 'relaticle');

    // Assert the result
    expect($result)->toBe(125);

    // Verify the HTTP request was made
    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.github.com/repos/Relaticle/relaticle' &&
               $request->hasHeader('Accept', 'application/vnd.github.v3+json');
    });
});

it('uses cached stars count on subsequent calls', function () {
    // Mock HTTP response
    Http::fake([
        'api.github.com/repos/Relaticle/relaticle' => Http::response([
            'stargazers_count' => 125,
        ], 200),
    ]);

    // First call should hit the API
    $firstResult = $this->service->getStarsCount('Relaticle', 'relaticle');
    expect($firstResult)->toBe(125);

    // Verify the API was called
    Http::assertSentCount(1);

    // Second call should use the cache and not hit the API again
    $secondResult = $this->service->getStarsCount('Relaticle', 'relaticle');
    expect($secondResult)->toBe(125);

    // Still only 1 call total
    Http::assertSentCount(1);
});

it('returns 0 when API call fails', function () {
    // Mock HTTP failure response
    Http::fake([
        'api.github.com/repos/Relaticle/relaticle' => Http::response(null, 500),
    ]);

    // Call the service
    $result = $this->service->getStarsCount();

    // Should return 0 on failure
    expect($result)->toBe(0);
});

it('returns 0 when API throws exception', function () {
    // Mock HTTP exception
    Http::fake(function () {
        throw new \Exception('Network error');
    });

    // Call the service
    $result = $this->service->getStarsCount();

    // Should return 0 on exception
    expect($result)->toBe(0);
});

it('formats small numbers as plain numbers', function () {
    // Mock HTTP response
    Http::fake([
        'api.github.com/repos/Relaticle/relaticle' => Http::response([
            'stargazers_count' => 42,
        ], 200),
    ]);

    // Call the service
    $result = $this->service->getFormattedStarsCount('Relaticle', 'relaticle');

    // For small numbers, should return as is
    expect($result)->toBe('42');
});

it('uses abbreviation for large star counts', function () {
    // Mock HTTP response with large value
    Http::fake([
        'api.github.com/repos/Relaticle/relaticle' => Http::response([
            'stargazers_count' => 12500,
        ], 200),
    ]);

    // Call the service
    $result = $this->service->getFormattedStarsCount('Relaticle', 'relaticle');

    // Should be abbreviated
    expect($result)->toBe('12.5K');
});

it('returns int even when cache contains string value', function () {
    // Simulate cache containing a string (as might happen from serialization)
    Cache::put('github_stars_Relaticle_relaticle', '125', now()->addMinutes(15));

    $result = $this->service->getStarsCount('Relaticle', 'relaticle');

    expect($result)->toBe(125)->toBeInt();
});
