<?php

declare(strict_types=1);

use App\Mcp\Servers\RelaticleServer;
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
use App\Models\Scopes\TeamScope;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

afterEach(function () {
    Note::clearBootedModels();
});

it('can create a note linked to a company', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->team])->create();

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
    $validCompany = Company::factory()->recycle([$this->user, $this->team])->create();
    $otherTeam = Team::factory()->create();
    $invalidCompany = Company::factory()->for($otherTeam)->create();

    RelaticleServer::actingAs($this->user)
        ->tool(CreateNoteTool::class, [
            'title' => 'Mixed',
            'company_ids' => [$validCompany->id, $invalidCompany->id],
        ])
        ->assertHasErrors(['company_ids.1']);
});

it('validates large arrays in bounded queries via MCP', function (): void {
    $companies = Company::factory()->count(10)->recycle([$this->user, $this->team])->create();

    DB::enableQueryLog();
    DB::flushQueryLog();

    RelaticleServer::actingAs($this->user)
        ->tool(CreateNoteTool::class, [
            'title' => 'Large',
            'company_ids' => $companies->pluck('id')->all(),
        ])
        ->assertOk();

    $lookups = collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => str_contains($q['query'], 'from "companies"') && str_contains($q['query'], 'team_id'))
        ->count();

    expect($lookups)->toBeLessThanOrEqual(2);
});

it('can update a note to link to an opportunity', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->team])->create();
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->team])->create();

    RelaticleServer::actingAs($this->user)
        ->tool(UpdateNoteTool::class, [
            'id' => $note->id,
            'opportunity_ids' => [$opportunity->id],
        ])
        ->assertOk();

    expect($note->refresh()->opportunities)->toHaveCount(1);
});

it('can detach all companies from a note via empty array', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->team])->create();
    $company = Company::factory()->recycle([$this->user, $this->team])->create();
    $note->companies()->attach($company);

    RelaticleServer::actingAs($this->user)
        ->tool(UpdateNoteTool::class, [
            'id' => $note->id,
            'company_ids' => [],
        ])
        ->assertOk();

    expect($note->refresh()->companies)->toHaveCount(0);
});

it('can get a note by ID', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->team])->create(['title' => 'Meeting Notes']);

    RelaticleServer::actingAs($this->user)
        ->tool(GetNoteTool::class, ['id' => $note->id])
        ->assertOk()
        ->assertSee('Meeting Notes');
});

it('can update a note via MCP tool', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->team])->create(['title' => 'Old Note']);

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
    $note = Note::factory()->recycle([$this->user, $this->team])->create(['title' => 'Delete Me']);

    RelaticleServer::actingAs($this->user)
        ->tool(DeleteNoteTool::class, [
            'id' => $note->id,
        ])
        ->assertOk()
        ->assertSee('has been deleted');

    expect($note->refresh()->trashed())->toBeTrue();
});

it('can attach a note to a company', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->team])->create();
    $company = Company::factory()->recycle([$this->user, $this->team])->create();

    RelaticleServer::actingAs($this->user)
        ->tool(AttachNoteToEntitiesTool::class, [
            'id' => $note->id,
            'company_ids' => [$company->id],
        ])
        ->assertOk();

    expect($note->refresh()->companies)->toHaveCount(1);
});

it('can detach a note from a company', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->team])->create();
    $company = Company::factory()->recycle([$this->user, $this->team])->create();
    $note->companies()->attach($company);

    RelaticleServer::actingAs($this->user)
        ->tool(DetachNoteFromEntitiesTool::class, [
            'id' => $note->id,
            'company_ids' => [$company->id],
        ])
        ->assertOk();

    expect($note->refresh()->companies)->toHaveCount(0);
});

it('attach does not remove existing links', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->team])->create();
    $company1 = Company::factory()->recycle([$this->user, $this->team])->create();
    $company2 = Company::factory()->recycle([$this->user, $this->team])->create();
    $note->companies()->attach($company1);

    RelaticleServer::actingAs($this->user)
        ->tool(AttachNoteToEntitiesTool::class, [
            'id' => $note->id,
            'company_ids' => [$company2->id],
        ])
        ->assertOk();

    expect($note->refresh()->companies)->toHaveCount(2);
});

describe('team scoping', function () {
    beforeEach(function () {
        Note::addGlobalScope(new TeamScope);
    });

    it('scopes notes to current team', function (): void {
        $otherNote = Note::withoutEvents(fn () => Note::factory()->create([
            'team_id' => Team::factory()->create()->id,
            'title' => 'Other Team Note',
        ]));
        $ownNote = Note::factory()->recycle([$this->user, $this->team])->create(['title' => 'Own Team Note']);

        RelaticleServer::actingAs($this->user)
            ->tool(ListNotesTool::class)
            ->assertOk()
            ->assertSee('Own Team Note')
            ->assertDontSee('Other Team Note');
    });

    it('cannot update a note from another team', function (): void {
        $otherNote = Note::withoutEvents(fn () => Note::factory()->create([
            'team_id' => Team::factory()->create()->id,
        ]));

        RelaticleServer::actingAs($this->user)
            ->tool(UpdateNoteTool::class, [
                'id' => $otherNote->id,
                'title' => 'Hacked',
            ])
            ->assertHasErrors(['not found']);
    });

    it('cannot delete a note from another team', function (): void {
        $otherNote = Note::withoutEvents(fn () => Note::factory()->create([
            'team_id' => Team::factory()->create()->id,
        ]));

        RelaticleServer::actingAs($this->user)
            ->tool(DeleteNoteTool::class, [
                'id' => $otherNote->id,
            ])
            ->assertHasErrors(['not found']);
    });

    it('cannot get a note from another team', function (): void {
        $otherNote = Note::withoutEvents(fn () => Note::factory()->create([
            'team_id' => Team::factory()->create()->id,
        ]));

        RelaticleServer::actingAs($this->user)
            ->tool(GetNoteTool::class, [
                'id' => $otherNote->id,
            ])
            ->assertHasErrors(['not found']);
    });
});
