<?php

declare(strict_types=1);

use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\Note\DeleteNoteTool;
use App\Mcp\Tools\Note\ListNotesTool;
use App\Mcp\Tools\Note\UpdateNoteTool;
use App\Models\Note;
use App\Models\Scopes\TeamScope;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

afterEach(function () {
    Note::clearBootedModels();
});

it('can update a note via MCP tool', function (): void {
    $note = Note::factory()->for($this->team)->create(['title' => 'Old Note']);

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
    $note = Note::factory()->for($this->team)->create(['title' => 'Delete Me']);

    RelaticleServer::actingAs($this->user)
        ->tool(DeleteNoteTool::class, [
            'id' => $note->id,
        ])
        ->assertOk()
        ->assertSee('has been deleted');

    expect($note->refresh()->trashed())->toBeTrue();
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
        $ownNote = Note::factory()->for($this->team)->create(['title' => 'Own Team Note']);

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
            ]);
    })->throws(ModelNotFoundException::class);

    it('cannot delete a note from another team', function (): void {
        $otherNote = Note::withoutEvents(fn () => Note::factory()->create([
            'team_id' => Team::factory()->create()->id,
        ]));

        RelaticleServer::actingAs($this->user)
            ->tool(DeleteNoteTool::class, [
                'id' => $otherNote->id,
            ]);
    })->throws(ModelNotFoundException::class);
});
