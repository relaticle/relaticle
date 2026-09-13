<?php

declare(strict_types=1);

use App\Actions\Note\AttachNoteRelationships;
use App\Actions\Note\DetachNoteRelationships;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\BaseAttachTool;
use App\Mcp\Tools\BaseCreateTool;
use App\Mcp\Tools\BaseDeleteTool;
use App\Mcp\Tools\BaseDetachTool;
use App\Mcp\Tools\BaseListTool;
use App\Mcp\Tools\BaseShowTool;
use App\Mcp\Tools\BaseUpdateTool;
use App\Mcp\Tools\Concerns\SerializesRelatedModels;
use App\Mcp\Tools\Note\AttachNoteToEntitiesTool;
use App\Mcp\Tools\Note\CreateNoteTool;
use App\Mcp\Tools\Note\DeleteNoteTool;
use App\Mcp\Tools\Note\DetachNoteFromEntitiesTool;
use App\Mcp\Tools\Note\GetNoteTool;
use App\Mcp\Tools\Note\ListNotesTool;
use App\Mcp\Tools\Note\UpdateNoteTool;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

mutates(
    AttachNoteRelationships::class,
    AttachNoteToEntitiesTool::class,
    BaseAttachTool::class,
    BaseCreateTool::class,
    BaseDeleteTool::class,
    BaseDetachTool::class,
    BaseListTool::class,
    BaseShowTool::class,
    BaseUpdateTool::class,
    CreateNoteTool::class,
    DeleteNoteTool::class,
    DetachNoteRelationships::class,
    DetachNoteFromEntitiesTool::class,
    GetNoteTool::class,
    ListNotesTool::class,
    SerializesRelatedModels::class,
    UpdateNoteTool::class,
);

beforeEach(function () {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
});

afterEach(function () {
    Note::clearBootedModels();
});

it('can create a note linked to a company', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    RelaticleServer::actingAs($this->user)
        ->tool(CreateNoteTool::class, [
            'title' => 'Meeting Notes',
            'company_ids' => [$company->id],
        ])
        ->assertOk()
        ->assertSee('Meeting Notes');

    $note = Note::query()->where('title', 'Meeting Notes')->firstOrFail();
    expect($note->companies)->toHaveCount(1)
        ->and($note->companies->first()->id)->toBe($company->id);
});

it('reports per-item validation errors with correct array index via MCP', function (): void {
    $validCompany = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $otherWorkspace = Workspace::factory()->create();
    $invalidCompany = Company::factory()->for($otherWorkspace)->create();

    RelaticleServer::actingAs($this->user)
        ->tool(CreateNoteTool::class, [
            'title' => 'Mixed',
            'company_ids' => [$validCompany->id, $invalidCompany->id],
        ])
        ->assertHasErrors(['company_ids.1']);
});

it('validates large arrays in bounded queries via MCP', function (): void {
    $companies = Company::factory()->count(10)->recycle([$this->user, $this->workspace])->create();

    DB::enableQueryLog();
    DB::flushQueryLog();

    RelaticleServer::actingAs($this->user)
        ->tool(CreateNoteTool::class, [
            'title' => 'Large',
            'company_ids' => $companies->pluck('id')->all(),
        ])
        ->assertOk();

    $lookups = collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => str_contains($q['query'], 'from "companies"') && str_contains($q['query'], 'workspace_id'))
        ->count();

    expect($lookups)->toBeLessThanOrEqual(2);
});

it('can update a note to link to an opportunity', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();

    RelaticleServer::actingAs($this->user)
        ->tool(UpdateNoteTool::class, [
            'id' => $note->id,
            'opportunity_ids' => [$opportunity->id],
        ])
        ->assertOk();

    expect($note->refresh()->opportunities)->toHaveCount(1);
});

it('can detach all companies from a note via empty array', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $note->companies()->attach($company);

    RelaticleServer::actingAs($this->user)
        ->tool(UpdateNoteTool::class, [
            'id' => $note->id,
            'company_ids' => [],
        ])
        ->assertOk();

    expect($note->refresh()->companies)->toBeEmpty();
});

it('can get a note by ID', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create(['title' => 'Meeting Notes']);

    RelaticleServer::actingAs($this->user)
        ->tool(GetNoteTool::class, ['id' => $note->id])
        ->assertOk()
        ->assertSee('Meeting Notes');
});

it('can update a note via MCP tool', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create(['title' => 'Old Note']);

    RelaticleServer::actingAs($this->user)
        ->tool(UpdateNoteTool::class, [
            'id' => $note->id,
            'title' => 'New Note',
        ])
        ->assertOk()
        ->assertSee('New Note');

    expect($note->refresh()->title)->toBe('New Note');
});

it('can delete a note via MCP tool', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create(['title' => 'Delete Me']);

    RelaticleServer::actingAs($this->user)
        ->tool(DeleteNoteTool::class, [
            'id' => $note->id,
        ])
        ->assertOk()
        ->assertSee('has been deleted');

    expect($note->refresh()->trashed())->toBeTrue();
});

it('can attach a note to a company', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    RelaticleServer::actingAs($this->user)
        ->tool(AttachNoteToEntitiesTool::class, [
            'id' => $note->id,
            'company_ids' => [$company->id],
        ])
        ->assertOk();

    expect($note->refresh()->companies)->toHaveCount(1);
});

it('can detach a note from a company', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $note->companies()->attach($company);

    RelaticleServer::actingAs($this->user)
        ->tool(DetachNoteFromEntitiesTool::class, [
            'id' => $note->id,
            'company_ids' => [$company->id],
        ])
        ->assertOk();

    expect($note->refresh()->companies)->toBeEmpty();
});

it('attach does not remove existing links', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $company1 = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $company2 = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $note->companies()->attach($company1);

    RelaticleServer::actingAs($this->user)
        ->tool(AttachNoteToEntitiesTool::class, [
            'id' => $note->id,
            'company_ids' => [$company2->id],
        ])
        ->assertOk();

    expect($note->refresh()->companies)->toHaveCount(2);
});

it('locks the note while attaching relationships', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    DB::enableQueryLog();
    DB::flushQueryLog();

    RelaticleServer::actingAs($this->user)
        ->tool(AttachNoteToEntitiesTool::class, [
            'id' => $note->id,
            'company_ids' => [$company->id],
        ])
        ->assertOk();

    expect(collect(DB::getQueryLog())->contains(
        fn (array $query): bool => str_contains($query['query'], 'from "notes"')
            && str_contains($query['query'], 'for update'),
    ))->toBeTrue();
});

it('cannot attach relationships to a note outside the current workspace', function (): void {
    $otherWorkspace = Workspace::factory()->for($this->user, 'owner')->create();
    $this->user->unsetRelation('ownedWorkspaces');
    $otherNote = Note::withoutEvents(fn () => Note::factory()->for($otherWorkspace)->create());
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    RelaticleServer::actingAs($this->user)
        ->tool(AttachNoteToEntitiesTool::class, [
            'id' => $otherNote->id,
            'company_ids' => [$company->id],
        ])
        ->assertHasErrors();

    expect($otherNote->companies()->whereKey($company->id)->exists())->toBeFalse();
});

it('cannot detach relationships from a note outside the current workspace', function (): void {
    $otherWorkspace = Workspace::factory()->for($this->user, 'owner')->create();
    $this->user->unsetRelation('ownedWorkspaces');
    $otherNote = Note::withoutEvents(fn () => Note::factory()->for($otherWorkspace)->create());
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $otherNote->companies()->attach($company);

    RelaticleServer::actingAs($this->user)
        ->tool(DetachNoteFromEntitiesTool::class, [
            'id' => $otherNote->id,
            'company_ids' => [$company->id],
        ])
        ->assertHasErrors();

    expect($otherNote->companies()->whereKey($company->id)->exists())->toBeTrue();
});

describe('workspace scoping', function () {
    beforeEach(function () {
        Note::addGlobalScope(new WorkspaceScope);
    });

    it('scopes notes to current workspace', function (): void {
        $otherNote = Note::withoutEvents(fn () => Note::factory()->create([
            'workspace_id' => Workspace::factory()->create()->id,
            'title' => 'Other Workspace Note',
        ]));
        $ownNote = Note::factory()->recycle([$this->user, $this->workspace])->create(['title' => 'Own Workspace Note']);

        RelaticleServer::actingAs($this->user)
            ->tool(ListNotesTool::class)
            ->assertOk()
            ->assertSee('Own Workspace Note')
            ->assertDontSee('Other Workspace Note');
    });

    it('cannot update a note from another workspace', function (): void {
        $otherNote = Note::withoutEvents(fn () => Note::factory()->create([
            'workspace_id' => Workspace::factory()->create()->id,
        ]));

        RelaticleServer::actingAs($this->user)
            ->tool(UpdateNoteTool::class, [
                'id' => $otherNote->id,
                'title' => 'Hacked',
            ])
            ->assertHasErrors(['not found']);
    });

    it('cannot delete a note from another workspace', function (): void {
        $otherNote = Note::withoutEvents(fn () => Note::factory()->create([
            'workspace_id' => Workspace::factory()->create()->id,
        ]));

        RelaticleServer::actingAs($this->user)
            ->tool(DeleteNoteTool::class, [
                'id' => $otherNote->id,
            ])
            ->assertHasErrors(['not found']);
    });

    it('cannot get a note from another workspace', function (): void {
        $otherNote = Note::withoutEvents(fn () => Note::factory()->create([
            'workspace_id' => Workspace::factory()->create()->id,
        ]));

        RelaticleServer::actingAs($this->user)
            ->tool(GetNoteTool::class, [
                'id' => $otherNote->id,
            ])
            ->assertHasErrors(['not found']);
    });
});
