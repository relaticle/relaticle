<?php

declare(strict_types=1);

use App\Mcp\Resources\NoteSchemaResource;
use App\Mcp\Resources\OpportunitySchemaResource;
use App\Mcp\Resources\PeopleSchemaResource;
use App\Mcp\Resources\TaskSchemaResource;
use App\Mcp\Servers\RelaticleServer;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
});

it('can read the people schema resource', function (): void {
    RelaticleServer::actingAs($this->user)
        ->resource(PeopleSchemaResource::class)
        ->assertOk()
        ->assertSee('people')
        ->assertSee('custom_fields');
});

it('can read the opportunity schema resource', function (): void {
    RelaticleServer::actingAs($this->user)
        ->resource(OpportunitySchemaResource::class)
        ->assertOk()
        ->assertSee('opportunity')
        ->assertSee('custom_fields');
});

it('can read the task schema resource', function (): void {
    RelaticleServer::actingAs($this->user)
        ->resource(TaskSchemaResource::class)
        ->assertOk()
        ->assertSee('task')
        ->assertSee('custom_fields');
});

it('can read the note schema resource', function (): void {
    RelaticleServer::actingAs($this->user)
        ->resource(NoteSchemaResource::class)
        ->assertOk()
        ->assertSee('note')
        ->assertSee('custom_fields');
});
