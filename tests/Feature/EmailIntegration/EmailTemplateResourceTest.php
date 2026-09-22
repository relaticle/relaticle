<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource\Pages\ManageEmailTemplates;
use Relaticle\EmailIntegration\Models\EmailTemplate;

mutates(EmailTemplate::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
});

it('bulk delete removes the user\'s own templates', function (): void {
    $mineA = EmailTemplate::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
    ]);
    $mineB = EmailTemplate::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
    ]);

    livewire(ManageEmailTemplates::class)
        ->selectTableRecords([$mineA, $mineB])
        ->callAction([['name' => 'delete', 'context' => ['table' => true, 'bulk' => true]]]);

    expect(EmailTemplate::whereKey($mineA->getKey())->exists())->toBeFalse()
        ->and(EmailTemplate::whereKey($mineB->getKey())->exists())->toBeFalse();
});

it('bulk delete preserves a shared template created by another user', function (): void {
    $mine = EmailTemplate::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
    ]);

    $otherUser = User::factory()->create();
    $this->workspace->users()->attach($otherUser);

    $theirShared = EmailTemplate::factory()->shared()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $otherUser->id,
    ]);

    livewire(ManageEmailTemplates::class)
        ->selectTableRecords([$mine, $theirShared])
        ->callAction([['name' => 'delete', 'context' => ['table' => true, 'bulk' => true]]]);

    expect(EmailTemplate::whereKey($mine->getKey())->exists())->toBeFalse()
        ->and(EmailTemplate::whereKey($theirShared->getKey())->exists())->toBeTrue();
});

it('denies template writes to a creator who no longer belongs to the workspace', function (): void {
    $otherWorkspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $otherWorkspace->users()->attach($this->user, ['role' => 'editor']);

    $template = EmailTemplate::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'created_by' => $this->user->id,
    ]);

    expect($this->user->can('update', $template))->toBeTrue();

    $otherWorkspace->users()->detach($this->user);
    $this->user->unsetRelation('workspaces');

    expect($this->user->can('update', $template))->toBeFalse()
        ->and($this->user->can('delete', $template))->toBeFalse()
        ->and($this->user->can('forceDelete', $template))->toBeFalse()
        ->and($this->user->can('restore', $template))->toBeFalse();
});
