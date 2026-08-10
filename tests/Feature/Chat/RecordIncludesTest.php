<?php

declare(strict_types=1);

use App\Actions\Note\UpdateNote;
use App\Actions\Task\UpdateTask;
use App\Models\Company;
use App\Models\CustomField;
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

it('returns custom field values on included items, not just native columns', function (): void {
    $acme = Company::factory()->for($this->team)->create(['name' => 'Acme']);

    $note = Note::factory()->for($this->team)->create(['title' => 'Discovery call']);
    $acme->notes()->attach($note);
    resolve(UpdateNote::class)->execute($this->user, $note, [
        'custom_fields' => ['body' => 'They churn because onboarding takes six weeks.'],
    ]);

    $task = Task::factory()->for($this->team)->create(['title' => 'Send proposal']);
    $acme->tasks()->attach($task);
    resolve(UpdateTask::class)->execute($this->user, $task, [
        'custom_fields' => ['description' => 'Include the six-week onboarding fix.'],
    ]);

    $payload = json_decode(resolve(GetCompanyTool::class)->handle(new Request([
        'id' => (string) $acme->getKey(),
        'include' => ['notes', 'tasks'],
    ])), true);

    expect($payload['included']['notes']['items'][0]['attributes']['custom_fields']['body'])
        ->toContain('onboarding takes six weeks')
        ->and($payload['included']['tasks']['items'][0]['attributes']['custom_fields']['description'])
        ->toContain('six-week onboarding fix');
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

it('excludes a related record that belongs to another team, even when attached via pivot', function (): void {
    $acme = Company::factory()->for($this->team)->create(['name' => 'Acme']);

    $otherUser = User::factory()->withPersonalTeam()->create();
    $crossTeamNote = Note::factory()->for($otherUser->currentTeam)->create(['title' => 'Not yours']);
    $acme->notes()->attach($crossTeamNote);

    $ownNote = Note::factory()->for($this->team)->create(['title' => 'Discovery call']);
    $acme->notes()->attach($ownNote);

    $payload = json_decode(resolve(GetCompanyTool::class)->handle(new Request([
        'id' => (string) $acme->getKey(),
        'include' => ['notes'],
    ])), true);

    expect($payload['included']['notes']['showing'])->toBe(1)
        ->and(array_column(array_column($payload['included']['notes']['items'], 'attributes'), 'title'))
        ->toBe(['Discovery call']);
});

it('strips HTML and truncates a long free-text custom field value on an included item', function (): void {
    $acme = Company::factory()->for($this->team)->create(['name' => 'Acme']);

    $note = Note::factory()->for($this->team)->create(['title' => 'Discovery call']);
    $acme->notes()->attach($note);

    $longBody = '<p>'.str_repeat('Onboarding takes six weeks and churn follows. ', 40).'</p>';
    resolve(UpdateNote::class)->execute($this->user, $note, [
        'custom_fields' => ['body' => $longBody],
    ]);

    $payload = json_decode(resolve(GetCompanyTool::class)->handle(new Request([
        'id' => (string) $acme->getKey(),
        'include' => ['notes'],
    ])), true);

    $body = $payload['included']['notes']['items'][0]['attributes']['custom_fields']['body'];

    expect($body)->not->toContain('<p>')
        ->and($body)->not->toContain('</p>')
        ->and(mb_strlen($body))->toBeLessThanOrEqual(503)
        ->and($body)->toEndWith('...');
});

it('does not strip or truncate a non-text custom field value on an included item', function (): void {
    $acme = Company::factory()->for($this->team)->create(['name' => 'Acme']);

    $task = Task::factory()->for($this->team)->create(['title' => 'Send proposal']);
    $acme->tasks()->attach($task);

    $priorityField = CustomField::query()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'priority')
        ->with('options')
        ->firstOrFail();
    $highOption = $priorityField->options->firstWhere('name', 'High');

    resolve(UpdateTask::class)->execute($this->user, $task, [
        'custom_fields' => ['priority' => (string) $highOption->getKey()],
    ]);

    $payload = json_decode(resolve(GetCompanyTool::class)->handle(new Request([
        'id' => (string) $acme->getKey(),
        'include' => ['tasks'],
    ])), true);

    expect($payload['included']['tasks']['items'][0]['attributes']['custom_fields']['priority'])
        ->toBe([
            'id' => (string) $highOption->getKey(),
            'label' => 'High',
        ]);
});
