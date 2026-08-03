<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Relaticle\Chat\Services\ChatContextService;

mutates(ChatContextService::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);
});

function recordUrl(string $tenantSlug, string $segment, string $id): string
{
    return "https://consolidate-ask-relaticle.test/app/{$tenantSlug}/{$segment}/{$id}";
}

it('resolves a record from a view-page url', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Acme']);

    $context = resolve(ChatContextService::class)->getContextForUrl(
        recordUrl($this->team->slug, 'companies', (string) $company->getKey()),
    );

    expect($context['record_type'])->toBe('company')
        ->and($context['record_id'])->toBe((string) $company->getKey())
        ->and($context['record_name'])->toBe('Acme');
});

it('returns empty context for a list page with no record', function (): void {
    $context = resolve(ChatContextService::class)->getContextForUrl(
        "https://consolidate-ask-relaticle.test/app/{$this->team->slug}/companies",
    );

    expect($context['record_type'])->toBeNull()
        ->and($context['record_id'])->toBeNull();
});

it('refuses a record belonging to another team', function (): void {
    $otherUser = User::factory()->withPersonalTeam()->create();
    $theirs = Company::factory()->for($otherUser->currentTeam)->create(['name' => 'Theirs']);

    $context = resolve(ChatContextService::class)->getContextForUrl(
        recordUrl($this->team->slug, 'companies', (string) $theirs->getKey()),
    );

    expect($context['record_type'])->toBeNull()
        ->and($context['record_id'])->toBeNull()
        ->and($context['record_name'])->toBeNull();
});

it('returns empty context for an unroutable url without throwing', function (): void {
    $context = resolve(ChatContextService::class)->getContextForUrl('https://example.com/not/a/route');

    expect($context['record_type'])->toBeNull();
});

it('returns empty context for a malformed url without throwing', function (): void {
    $context = resolve(ChatContextService::class)->getContextForUrl('not-a-url');

    expect($context['record_type'])->toBeNull();
});
