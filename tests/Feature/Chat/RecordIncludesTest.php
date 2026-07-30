<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\Company\GetCompanyTool;

mutates(GetCompanyTool::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    Auth::guard('web')->setUser($this->user);
});

it('returns no included key when include is omitted', function (): void {
    $acme = Company::factory()->for($this->team)->create(['name' => 'Acme']);

    $payload = json_decode(resolve(GetCompanyTool::class)->handle(new Request([
        'id' => (string) $acme->getKey(),
    ])), true);

    expect($payload)->not->toHaveKey('included');
});

it('returns related notes and tasks with totals when requested', function (): void {
    $acme = Company::factory()->for($this->team)->create(['name' => 'Acme']);
    $acme->notes()->attach(Note::factory()->for($this->team)->create(['title' => 'Discovery call']));
    $acme->tasks()->attach(Task::factory()->for($this->team)->create(['title' => 'Send proposal']));

    $payload = json_decode(resolve(GetCompanyTool::class)->handle(new Request([
        'id' => (string) $acme->getKey(),
        'include' => ['notes', 'tasks'],
    ])), true);

    expect($payload['included']['notes']['total'])->toBe(1)
        ->and($payload['included']['notes']['showing'])->toBe(1)
        ->and(array_column(array_column($payload['included']['notes']['items'], 'attributes'), 'title'))->toContain('Discovery call')
        ->and(array_column(array_column($payload['included']['tasks']['items'], 'attributes'), 'title'))->toContain('Send proposal');
});

it('caps included items at ten while reporting the true total', function (): void {
    $acme = Company::factory()->for($this->team)->create(['name' => 'Acme']);

    foreach (range(1, 14) as $i) {
        $acme->notes()->attach(Note::factory()->for($this->team)->create(['title' => "Note {$i}"]));
    }

    $payload = json_decode(resolve(GetCompanyTool::class)->handle(new Request([
        'id' => (string) $acme->getKey(),
        'include' => ['notes'],
    ])), true);

    expect($payload['included']['notes']['total'])->toBe(14)
        ->and($payload['included']['notes']['showing'])->toBe(10)
        ->and($payload['included']['notes']['items'])->toHaveCount(10);
});

it('returns an error naming the valid includes when given an unknown one', function (): void {
    $acme = Company::factory()->for($this->team)->create(['name' => 'Acme']);

    $payload = json_decode(resolve(GetCompanyTool::class)->handle(new Request([
        'id' => (string) $acme->getKey(),
        'include' => ['invoices'],
    ])), true);

    expect($payload)->toHaveKey('error')
        ->and($payload['error'])->toContain('invoices')
        ->and($payload['error'])->toContain('notes');
});

it('does not include records from another team', function (): void {
    $otherUser = User::factory()->withPersonalTeam()->create();
    $theirs = Company::factory()->for($otherUser->currentTeam)->create(['name' => 'Theirs']);
    $theirs->notes()->attach(Note::factory()->for($otherUser->currentTeam)->create(['title' => 'Their note']));

    $payload = json_decode(resolve(GetCompanyTool::class)->handle(new Request([
        'id' => (string) $theirs->getKey(),
        'include' => ['notes'],
    ])), true);

    expect($payload)->toHaveKey('error');
});
