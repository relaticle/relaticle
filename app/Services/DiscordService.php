<?php

declare(strict_types=1);

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

final readonly class DiscordService
{
    public function getMemberCount(int $cacheMinutes = 60): int
    {
        $inviteCode = $this->inviteCode();

        if ($inviteCode === null) {
            return 0;
        }

        $cacheKey = "discord_members_{$inviteCode}";
        $lastGoodKey = "{$cacheKey}_last_good";

        return (int) Cache::remember($cacheKey, now()->addMinutes($cacheMinutes), function () use ($inviteCode, $lastGoodKey): int {
            try {
                $response = Http::get("https://discord.com/api/v10/invites/{$inviteCode}", ['with_counts' => 'true']);

                if ($response->successful()) {
                    $members = (int) $response->json('approximate_member_count', 0);
                    Cache::forever($lastGoodKey, $members);

                    return $members;
                }

                Log::warning('Failed to fetch Discord members: '.$response->status());
            } catch (Exception $e) {
                Log::error('Error fetching Discord members: '.$e->getMessage());
            }

            return (int) Cache::get($lastGoodKey, 0);
        });
    }

    public function getFormattedMemberCount(int $cacheMinutes = 60): ?string
    {
        $members = $this->getMemberCount($cacheMinutes);

        if ($members === 0) {
            return null;
        }

        if ($members >= 1000) {
            return (string) Number::abbreviate($members, 1);
        }

        return (string) $members;
    }

    private function inviteCode(): ?string
    {
        $inviteUrl = config('services.discord.invite_url');

        if (! is_string($inviteUrl) || $inviteUrl === '') {
            return null;
        }

        $code = Str::of((string) parse_url($inviteUrl, PHP_URL_PATH))->trim('/')->afterLast('/')->toString();

        return $code === '' ? null : $code;
    }
}
