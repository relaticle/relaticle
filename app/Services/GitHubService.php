<?php

declare(strict_types=1);

namespace App\Services;

use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;

final readonly class GitHubService
{
    /**
     * Get the stargazers to count for a GitHub repository
     *
     * @param  string  $owner  The repository owner
     * @param  string  $repo  The repository name
     * @param  int  $cacheMinutes  Minutes to cache the result (default: 15)
     */
    public function getStarsCount(string $owner = 'Relaticle', string $repo = 'relaticle', int $cacheMinutes = 15): int
    {
        $cacheKey = "github_stars_{$owner}_{$repo}";
        $lastGoodKey = "{$cacheKey}_last_good";

        return (int) Cache::remember($cacheKey, now()->addMinutes($cacheMinutes), function () use ($owner, $repo, $lastGoodKey): int {
            try {
                /** @var Response $response */
                $response = Http::withHeaders([
                    'Accept' => 'application/vnd.github.v3+json',
                ])->get("https://api.github.com/repos/{$owner}/{$repo}");

                if ($response->successful()) {
                    $stars = (int) $response->json('stargazers_count', 0);
                    Cache::forever($lastGoodKey, $stars);

                    return $stars;
                }

                Log::warning('Failed to fetch GitHub stars: '.$response->status());
            } catch (Exception $e) {
                Log::error('Error fetching GitHub stars: '.$e->getMessage());
            }

            return (int) Cache::get($lastGoodKey, 0);
        });
    }

    /**
     * Get the formatted stargazers to count for a GitHub repository
     *
     * @param  string  $owner  The repository owner
     * @param  string  $repo  The repository name
     * @param  int  $cacheMinutes  Minutes to cache the result (default: 15)
     */
    public function getFormattedStarsCount(string $owner = 'Relaticle', string $repo = 'relaticle', int $cacheMinutes = 15): string
    {
        $starsCount = $this->getStarsCount($owner, $repo, $cacheMinutes);

        if ($starsCount >= 1000) {
            return (string) Number::abbreviate($starsCount, 1);
        }

        return (string) $starsCount;
    }
}
