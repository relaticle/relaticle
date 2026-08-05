<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\Note\ListNotesTool;
use Relaticle\Chat\Tools\Task\ListTasksTool;

mutates(ListNotesTool::class);
mutates(ListTasksTool::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    Auth::guard('web')->setUser($this->user);
});

it('scopes notes to the record they are attached to', function (): void {
    $acme = Company::factory()->for($this->team)->create(['name' => 'Acme']);
    $globex = Company::factory()->for($this->team)->create(['name' => 'Globex']);

    $acmeNote = Note::factory()->for($this->team)->create(['title' => 'Acme discovery call']);
    $globexNote = Note::factory()->for($this->team)->create(['title' => 'Globex renewal']);

    $acme->notes()->attach($acmeNote);
    $globex->notes()->attach($globexNote);

    $payload = json_decode(resolve(ListNotesTool::class)->handle(new Request([
        'notable_type' => 'company',
        'notable_id' => (string) $acme->getKey(),
    ])), true);

    $titles = array_column(array_column($payload, 'attributes'), 'title');
    expect($titles)
        ->toContain('Acme discovery call')
        ->not->toContain('Globex renewal');
});

it('scopes tasks to the company they are attached to', function (): void {
    $acme = Company::factory()->for($this->team)->create(['name' => 'Acme']);
    $globex = Company::factory()->for($this->team)->create(['name' => 'Globex']);

    $acmeTask = Task::factory()->for($this->team)->create(['title' => 'Send Acme proposal']);
    $globexTask = Task::factory()->for($this->team)->create(['title' => 'Chase Globex invoice']);

    $acme->tasks()->attach($acmeTask);
    $globex->tasks()->attach($globexTask);

    $payload = json_decode(resolve(ListTasksTool::class)->handle(new Request([
        'company_id' => (string) $acme->getKey(),
    ])), true);

    $titles = array_column(array_column($payload, 'attributes'), 'title');
    expect($titles)
        ->toContain('Send Acme proposal')
        ->not->toContain('Chase Globex invoice');
});

it('does not leak another team notes through the notable filter', function (): void {
    $mine = Company::factory()->for($this->team)->create(['name' => 'Mine']);
    $mineNote = Note::factory()->for($this->team)->create(['title' => 'My note']);
    $mine->notes()->attach($mineNote);

    $otherUser = User::factory()->withPersonalTeam()->create();
    $otherTeam = $otherUser->currentTeam;
    $theirs = Company::factory()->for($otherTeam)->create(['name' => 'Theirs']);
    $theirNote = Note::factory()->for($otherTeam)->create(['title' => 'Their secret note']);
    $theirs->notes()->attach($theirNote);

    $payload = json_decode(resolve(ListNotesTool::class)->handle(new Request([
        'notable_type' => 'company',
        'notable_id' => (string) $theirs->getKey(),
    ])), true);

    $titles = array_column(array_column($payload, 'attributes'), 'title');
    expect($titles)->not->toContain('Their secret note');
});
