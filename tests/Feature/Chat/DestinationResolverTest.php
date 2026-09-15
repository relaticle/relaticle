<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\Chat\Support\DestinationResolver;

mutates(DestinationResolver::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->user->switchWorkspace($this->user->ownedWorkspaces()->first());
    $this->actingAs($this->user);
});

it('resolves the custom fields destination to an app-panel url', function (): void {
    $url = app(DestinationResolver::class)->resolve('custom_fields', $this->user->currentWorkspace);

    expect($url)->toBeString()
        ->and($url)->toContain((string) $this->user->currentWorkspace->slug)
        ->and($url)->toContain('custom-fields');
});

it('resolves every declared destination to a non-null url', function (): void {
    $resolver = app(DestinationResolver::class);

    foreach (DestinationResolver::DESTINATIONS as $destination) {
        expect($resolver->resolve($destination, $this->user->currentWorkspace))
            ->toBeString("destination [{$destination}] should resolve to a url");
    }
});

it('returns null for an unknown destination', function (): void {
    expect(app(DestinationResolver::class)->resolve('does_not_exist', $this->user->currentWorkspace))
        ->toBeNull();
});

it('resolves every export destination to its list page with the export action deep-linked', function (): void {
    $resolver = app(DestinationResolver::class);

    $paths = [
        'export_companies' => 'companies',
        'export_people' => 'people',
        'export_opportunities' => 'opportunities',
        'export_tasks' => 'tasks',
        'export_notes' => 'notes',
    ];

    foreach ($paths as $destination => $path) {
        $url = $resolver->resolve($destination, $this->user->currentWorkspace);

        expect($url)->toBeString("destination [{$destination}] should resolve to a url")
            ->and($url)->toContain("/{$path}?")
            ->and($url)->toContain('action=export');
    }
});

it('resolves access_tokens to the workspace access tokens page', function (): void {
    $url = app(DestinationResolver::class)->resolve('access_tokens', $this->user->currentWorkspace);

    expect($url)->toBeString()
        ->and($url)->toContain((string) $this->user->currentWorkspace->slug)
        ->and($url)->toContain('access-tokens');
});

it('resolves connect_assistant to the public help page on the primary host', function (): void {
    config()->set('app.url', 'https://marketing.test');

    $url = app(DestinationResolver::class)->resolve('connect_assistant', $this->user->currentWorkspace);

    expect($url)->toBe('https://marketing.test/help/ai-assistant/connect-claude-or-chatgpt');
});
