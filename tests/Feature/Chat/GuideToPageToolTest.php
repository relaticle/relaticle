<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\User;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Serializer;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Support\DestinationResolver;
use Relaticle\Chat\Tools\GuideToPageTool;

mutates(GuideToPageTool::class);
mutates(DestinationResolver::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->user->switchWorkspace($this->user->ownedWorkspaces()->first());
    $this->actingAs($this->user);
});

it('returns a navigation payload with a url for a known destination', function (): void {
    $json = app(GuideToPageTool::class)->handle(new Request(['destination' => 'custom_fields']));
    $payload = json_decode($json, true);

    expect($payload['type'])->toBe('navigation')
        ->and($payload['destination'])->toBe('custom_fields')
        ->and($payload['url'])->toBeString()
        ->and($payload['url'])->toContain('custom-fields');
});

it('returns an error payload for an unknown destination', function (): void {
    $json = app(GuideToPageTool::class)->handle(new Request(['destination' => 'nope']));
    $payload = json_decode($json, true);

    expect($payload)->toHaveKey('error')
        ->and($payload)->not->toHaveKey('url');
});

it('does not create a pending action because it is not a write', function (): void {
    app(GuideToPageTool::class)->handle(new Request(['destination' => 'workspace_members']));

    expect(PendingAction::query()->count())->toBe(0);
});

/**
 * The model picks a destination from the schema description, so a key the
 * resolver knows but the description omits is unreachable. The two lists are
 * written by hand in separate files; this is the only thing stopping drift.
 */
it('advertises every resolvable destination in its schema description', function (): void {
    $schema = app(GuideToPageTool::class)->schema(new JsonSchemaTypeFactory);
    $destination = (new Serializer)->serialize($schema['destination']);

    foreach (DestinationResolver::DESTINATIONS as $key) {
        expect($destination['description'])->toContain($key);
    }
});

it('names the access tokens and connect assistant destinations in its schema description', function (): void {
    $schema = app(GuideToPageTool::class)->schema(new JsonSchemaTypeFactory);
    $destination = (new Serializer)->serialize($schema['destination']);

    expect($destination['description'])
        ->toContain('"access_tokens"')
        ->toContain('"connect_assistant"');
});

it('refuses a page the user cannot open instead of handing over a link that would 403', function (string $destination, WorkspaceRole $role): void {
    $workspace = $this->user->ownedWorkspaces()->first();
    $teammate = User::factory()->create();
    $workspace->users()->attach($teammate, ['role' => $role->value]);
    $teammate->switchWorkspace($workspace);
    $this->actingAs($teammate->fresh());

    $payload = json_decode(app(GuideToPageTool::class)->handle(new Request(['destination' => $destination])), true);

    expect($payload)->toHaveKey('error')
        ->and($payload)->not->toHaveKey('url')
        ->and($payload['error'])->toContain('cannot open');
})->with([
    'member to custom fields' => ['custom_fields', WorkspaceRole::Member],
    'member to members' => ['workspace_members', WorkspaceRole::Member],
    'viewer to an import' => ['import_companies', WorkspaceRole::Viewer],
    'viewer to an export' => ['export_companies', WorkspaceRole::Viewer],
]);

it('still links an admin to the pages admins can open', function (string $destination): void {
    $workspace = $this->user->ownedWorkspaces()->first();
    $admin = User::factory()->create();
    $workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);
    $admin->switchWorkspace($workspace);
    $this->actingAs($admin->fresh());

    $payload = json_decode(app(GuideToPageTool::class)->handle(new Request(['destination' => $destination])), true);

    expect($payload)->not->toHaveKey('error')
        ->and($payload['url'])->toBeString();
})->with(['custom_fields', 'workspace_members', 'import_companies', 'export_companies', 'access_tokens']);
