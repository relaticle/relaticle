<?php

declare(strict_types=1);

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;

final readonly class DockerHubService
{
    public function getPullCount(string $namespace = 'manukminasyan', string $repo = 'relaticle', int $cacheMinutes = 60): int
    {
        return (int) Cache::remember("dockerhub_pulls_{$namespace}_{$repo}", now()->addMinutes($cacheMinutes), function () use ($namespace, $repo): int {
            try {
                $response = Http::get("https://hub.docker.com/v2/repositories/{$namespace}/{$repo}/");

                if ($response->successful()) {
                    return (int) $response->json('pull_count', 0);
                }

                Log::warning('Failed to fetch Docker Hub pulls: '.$response->status());

                return 0;
            } catch (Exception $e) {
                Log::error('Error fetching Docker Hub pulls: '.$e->getMessage());

                return 0;
            }
        });
    }

    /**
     * Rounded down to the nearest thousand so the figure never overstates, e.g. 21,000+.
     */
    public function getFormattedPullCount(string $namespace = 'manukminasyan', string $repo = 'relaticle', int $cacheMinutes = 60): ?string
    {
        $pulls = $this->getPullCount($namespace, $repo, $cacheMinutes);

        if ($pulls < 1000) {
            return null;
        }

        return Number::format(intdiv($pulls, 1000) * 1000).'+';
    }
}
