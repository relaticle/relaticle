<?php

declare(strict_types=1);

use App\Actions\Note\UpdateNote;
use App\Actions\Task\UpdateTask;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\Company\GetCompanyTool;
use Relaticle\Chat\Tools\Company\ListCompaniesTool;
use Relaticle\Chat\Tools\Opportunity\ListOpportunitiesTool;
use Relaticle\Chat\Tools\People\ListPeopleTool;

mutates(GetCompanyTool::class);
mutates(ListCompaniesTool::class);
mutates(ListPeopleTool::class);
mutates(ListOpportunitiesTool::class);

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

    // `total` as well as `showing`: an unscoped loadCount would report the
    // cross-team note the items list omits, so one relation would state two
    // different totals here and in the list tool's row.
    expect($payload['included']['notes']['showing'])->toBe(1)
        ->and($payload['included']['notes']['total'])->toBe(1)
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

// --- list tool: `include` attaches related records per row and a chip column ---

it('lists companies with included opportunities and a chip column', function (): void {
    $acme = Company::factory()->for($this->team)->create(['name' => 'Acme']);
    Opportunity::factory()->count(4)->for($this->team)->create(['company_id' => $acme->getKey()]);

    // A second row with its own, smaller set of opportunities: proves the
    // per-row cap is applied per row, not once across the whole page (an
    // eager-load `->limit()` spanning every row in the page would starve
    // whichever row's related records did not sort first).
    $globex = Company::factory()->for($this->team)->create(['name' => 'Globex']);
    Opportunity::factory()->count(1)->for($this->team)->create(['company_id' => $globex->getKey()]);

    $payload = json_decode(resolve(ListCompaniesTool::class)->handle(new Request(['include' => ['opportunities']])), true);

    $acmeRow = collect($payload['data'])->firstWhere('id', (string) $acme->getKey());
    $globexRow = collect($payload['data'])->firstWhere('id', (string) $globex->getKey());

    expect($acmeRow['included']['opportunities']['total'])->toBe(4)
        ->and($acmeRow['included']['opportunities']['showing'])->toBe(3)
        ->and($acmeRow['included']['opportunities']['items'])->toHaveCount(3)
        ->and($acmeRow['included']['opportunities']['items'][0])->toHaveKeys(['id', 'name', 'url'])
        ->and($globexRow['included']['opportunities']['total'])->toBe(1)
        ->and($globexRow['included']['opportunities']['showing'])->toBe(1);

    $column = collect($payload['display_block']['columns'])->firstWhere('key', '_include_opportunities');
    expect($column)->not->toBeNull();

    $cell = collect($payload['display_block']['rows'])->firstWhere('id', (string) $acme->getKey())['cells']['_include_opportunities'];
    expect($cell)->toBeArray()->and($cell)->toHaveCount(3)
        ->and($cell[0])->toHaveKeys(['label', 'url', 'type'])
        ->and($cell[0]['type'])->toBe('opportunity');
});

it('rejects an unknown include on a list tool with the standard error envelope', function (): void {
    Company::factory()->for($this->team)->create(['name' => 'Acme']);

    $payload = json_decode(resolve(ListCompaniesTool::class)->handle(new Request(['include' => ['bogus']])), true);

    expect($payload)->toHaveKey('error')
        ->and($payload['error'])->toContain('bogus')
        ->and($payload['error'])->toContain('opportunities');
});

it('returns no included key on a list row when include is omitted', function (): void {
    Company::factory()->for($this->team)->create(['name' => 'Acme']);

    $payload = json_decode(resolve(ListCompaniesTool::class)->handle(new Request([])), true);

    expect($payload['data'][0])->not->toHaveKey('included');
});

it('excludes an included note that belongs to another team from a list row', function (): void {
    $acme = Company::factory()->for($this->team)->create(['name' => 'Acme']);

    $otherTeam = User::factory()->withPersonalTeam()->create()->currentTeam;
    $crossTeamNote = Note::factory()->for($otherTeam)->create(['title' => 'Not yours']);
    $acme->notes()->attach($crossTeamNote);

    $ownNote = Note::factory()->for($this->team)->create(['title' => 'Discovery call']);
    $acme->notes()->attach($ownNote);

    $payload = json_decode(resolve(ListCompaniesTool::class)->handle(new Request(['include' => ['notes']])), true);

    $row = collect($payload['data'])->firstWhere('id', (string) $acme->getKey());

    expect($row['included']['notes']['total'])->toBe(1)
        ->and($row['included']['notes']['showing'])->toBe(1)
        ->and($row['included']['notes']['items'][0]['name'])->toBe('Discovery call');
});

it('lists people with included tasks, truncated past the per-row limit', function (): void {
    $jane = People::factory()->for($this->team)->create(['name' => 'Jane']);
    $jane->tasks()->attach(Task::factory()->count(5)->for($this->team)->create());

    $payload = json_decode(resolve(ListPeopleTool::class)->handle(new Request(['include' => ['tasks']])), true);

    $row = collect($payload['data'])->firstWhere('id', (string) $jane->getKey());

    expect($row['included']['tasks']['total'])->toBe(5)
        ->and($row['included']['tasks']['showing'])->toBe(3)
        ->and($row['included']['tasks']['items'])->toHaveCount(3)
        ->and($row['included']['tasks']['items'][0])->toHaveKeys(['id', 'name', 'url']);
});

it('lists opportunities with included notes', function (): void {
    $opportunity = Opportunity::factory()->for($this->team)->create(['name' => 'Big deal']);
    $opportunity->notes()->attach(Note::factory()->count(2)->for($this->team)->create());

    $payload = json_decode(resolve(ListOpportunitiesTool::class)->handle(new Request(['include' => ['notes']])), true);

    $row = collect($payload['data'])->firstWhere('id', (string) $opportunity->getKey());

    expect($row['included']['notes']['total'])->toBe(2)
        ->and($row['included']['notes']['showing'])->toBe(2)
        ->and($row['included']['notes']['items'])->toHaveCount(2)
        ->and($row['included']['notes']['items'][0])->toHaveKeys(['id', 'name', 'url']);
});
