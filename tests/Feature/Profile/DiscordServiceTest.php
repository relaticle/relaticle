<?php

declare(strict_types=1);

use App\Services\DiscordService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

mutates(DiscordService::class);

beforeEach(function (): void {
    config()->set('services.discord.invite_url', 'https://discord.gg/abc123');

    Cache::forget('discord_members_abc123');
    Cache::forget('discord_members_abc123_last_good');
});

it('reads the member count from the invite named in config', function (): void {
    Http::fake(['discord.com/api/v10/invites/abc123*' => Http::response(['approximate_member_count' => 157])]);

    expect((new DiscordService)->getFormattedMemberCount())->toBe('157');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://discord.com/api/v10/invites/abc123?with_counts=true');
});

it('abbreviates member counts from a thousand up', function (): void {
    Http::fake(['discord.com/*' => Http::response(['approximate_member_count' => 1517])]);

    expect((new DiscordService)->getFormattedMemberCount())->toBe('1.5K');
});

it('accepts a discord.com invite url', function (): void {
    config()->set('services.discord.invite_url', 'https://discord.com/invite/abc123/');
    Http::fake(['discord.com/api/v10/invites/abc123*' => Http::response(['approximate_member_count' => 42])]);

    expect((new DiscordService)->getMemberCount())->toBe(42);
});

it('returns null without a request when no invite is configured', function (): void {
    config()->set('services.discord.invite_url', null);
    Http::fake();

    expect((new DiscordService)->getFormattedMemberCount())->toBeNull();

    Http::assertNothingSent();
});

it('returns null when Discord is unreachable', function (): void {
    Http::fake(['discord.com/*' => Http::response(null, 500)]);

    expect((new DiscordService)->getFormattedMemberCount())->toBeNull();
});

it('caches the member count', function (): void {
    Http::fake(['discord.com/*' => Http::response(['approximate_member_count' => 157])]);
    $service = new DiscordService;

    $service->getMemberCount();
    $service->getMemberCount();

    Http::assertSentCount(1);
});

it('keeps the last good member count when Discord fails on the next fetch', function (): void {
    Http::fake(['discord.com/*' => Http::sequence()
        ->push(['approximate_member_count' => 157])
        ->push(null, 500)]);
    $service = new DiscordService;

    expect($service->getFormattedMemberCount())->toBe('157');

    Cache::forget('discord_members_abc123');

    expect($service->getFormattedMemberCount())->toBe('157');
});
