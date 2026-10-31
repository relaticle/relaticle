<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Translation\Translator;
use Relaticle\EmailIntegration\Actions\DeleteEmailTemplatesAction;
use Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource;
use Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource\Pages\ManageEmailTemplates;
use Relaticle\EmailIntegration\Models\EmailTemplate;
use Relaticle\EmailIntegration\Policies\EmailTemplatePolicy;

mutates(EmailTemplate::class, EmailTemplatePolicy::class, DeleteEmailTemplatesAction::class, EmailTemplateResource::class);

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

    expect(EmailTemplate::query()->whereKey($mineA->getKey())->exists())->toBeFalse()
        ->and(EmailTemplate::query()->whereKey($mineB->getKey())->exists())->toBeFalse();
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

    expect(EmailTemplate::query()->whereKey($mine->getKey())->exists())->toBeFalse()
        ->and(EmailTemplate::query()->whereKey($theirShared->getKey())->exists())->toBeTrue();
});

it('lets a workspace admin manage an orphaned shared template', function (): void {
    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => 'admin']);

    $orphan = EmailTemplate::factory()->shared()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => null,
    ]);

    expect($admin->can('update', $orphan))->toBeTrue()
        ->and($admin->can('delete', $orphan))->toBeTrue();

    $this->actingAs($admin);
    Filament::setTenant($this->workspace);

    livewire(ManageEmailTemplates::class)
        ->assertTableActionVisible('edit', $orphan)
        ->assertTableActionVisible('delete', $orphan);
});

it('keeps an orphaned template shared when a workspace admin edits it', function (): void {
    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => 'admin']);

    $orphan = EmailTemplate::factory()->shared()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => null,
    ]);

    $this->actingAs($admin);
    Filament::setTenant($this->workspace);

    livewire(ManageEmailTemplates::class)
        ->callTableAction('edit', $orphan, data: [
            'name' => 'Renamed by admin',
            'is_shared' => false,
        ])
        ->assertHasNoTableActionErrors();

    expect($orphan->fresh())
        ->name->toBe('Renamed by admin')
        ->is_shared->toBeTrue();
});

it('lets a workspace admin bulk delete an orphaned shared template', function (): void {
    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => 'admin']);

    $orphan = EmailTemplate::factory()->shared()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => null,
    ]);

    $this->actingAs($admin);
    Filament::setTenant($this->workspace);

    livewire(ManageEmailTemplates::class)
        ->selectTableRecords([$orphan])
        ->callAction([['name' => 'delete', 'context' => ['table' => true, 'bulk' => true]]]);

    expect(EmailTemplate::query()->whereKey($orphan->getKey())->exists())->toBeFalse();
});

it('denies an orphaned shared template to a member without the email-manage capability', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => 'member']);

    $orphan = EmailTemplate::factory()->shared()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => null,
    ]);

    expect($member->can('update', $orphan))->toBeFalse()
        ->and($member->can('delete', $orphan))->toBeFalse();

    $this->actingAs($member);
    Filament::setTenant($this->workspace);

    livewire(ManageEmailTemplates::class)
        ->assertTableActionHidden('edit', $orphan)
        ->assertTableActionHidden('delete', $orphan);
});

it('denies template writes to a creator who no longer belongs to the workspace', function (): void {
    $otherWorkspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $otherWorkspace->users()->attach($this->user, ['role' => 'member']);

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

it('labels every column and action through the translator', function (): void {
    __('filament/resources/email-template.navigation_label');

    resolve(Translator::class)->addLines([
        'filament/resources/email-template.columns.name.label' => 'Column template title',
        'filament/resources/email-template.columns.subject.label' => 'Column subject line',
        'filament/resources/email-template.actions.edit.label' => 'Change this template',
        'filament/resources/email-template.actions.bulk_delete.label' => 'Remove chosen templates',
    ], 'en');

    $template = EmailTemplate::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
    ]);

    livewire(ManageEmailTemplates::class)
        ->assertTableColumnExists('name', fn (TextColumn $column): bool => $column->getLabel() === 'Column template title')
        ->assertTableColumnExists('subject', fn (TextColumn $column): bool => $column->getLabel() === 'Column subject line')
        ->assertActionHasLabel(TestAction::make('edit')->table($template), 'Change this template')
        ->assertActionHasLabel(TestAction::make('delete')->table()->bulk(), 'Remove chosen templates');
});
