<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Models\Company;
use App\Models\Note;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Tools\BaseWriteUpdateTool;
use Relaticle\Chat\Tools\Note\UpdateNoteTool;
use Relaticle\Chat\Tools\People\UpdatePersonTool;
use Relaticle\Chat\Tools\Task\UpdateTaskTool;

mutates(BaseWriteUpdateTool::class, PendingActionService::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
    Bus::fake();
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    Auth::guard('web')->setUser($this->user);
    $this->actingAs($this->user);
    Filament::setTenant($this->team);

    $this->convId = '019df900-6666-7000-8000-000000000001';
    DB::table('agent_conversations')->insert([
        'id' => $this->convId,
        'participant_type' => 'user',
        'participant_id' => (string) $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

function proposeNoteUpdates(string $convId, array $records): array
{
    $tool = resolve(UpdateNoteTool::class);
    $tool->setConversationId($convId);

    return json_decode($tool->handle(new Request(['records' => $records])), true);
}

function latestPending(User $user): PendingAction
{
    return PendingAction::query()->where('team_id', $user->currentTeam->getKey())->latest()->firstOrFail();
}

it('builds ONE per-item batch proposal with an old->new title diff for every note', function (): void {
    $notes = Note::factory()->count(3)->for($this->team)->sequence(
        ['title' => 'Test Note 1'], ['title' => 'Test Note 2'], ['title' => 'Test Note 3'],
    )->create();

    $result = proposeNoteUpdates($this->convId, $notes->map(fn (Note $note): array => [
        'id' => (string) $note->getKey(),
        'title' => "{$note->title} 🚀",
    ])->all());

    $pending = latestPending($this->user);

    expect($result['type'])->toBe('pending_action')
        ->and($result['operation'])->toBe('update')
        ->and($result['data']['_batch'])->toBeTrue()
        ->and($result['data']['records'][0])->not->toHaveKey('_record_id')
        ->and($pending->action_data['_batch'])->toBeTrue()
        ->and($pending->action_data['records'])->toHaveCount(3)
        ->and($pending->action_data['records'][1]['_record_id'])->toBe((string) $notes[1]->getKey())
        ->and($pending->action_data['records'][1]['_model_class'])->toBe(Note::class)
        ->and($pending->display_data['summary'])->toBe('Update 3 notes')
        ->and($pending->display_data['title'])->toBe('Update Notes')
        ->and($pending->display_data['items'][2]['fields'][0])->toEqual(['label' => 'Title', 'old' => 'Test Note 3', 'new' => 'Test Note 3 🚀']);
});

it('keeps the flat single-record shape when one record is passed', function (): void {
    $note = Note::factory()->for($this->team)->create(['title' => 'Solo']);

    proposeNoteUpdates($this->convId, [['id' => (string) $note->getKey(), 'title' => 'Solo 🚀']]);

    $pending = latestPending($this->user);

    expect($pending->action_data)->not->toHaveKey('_batch')
        ->and($pending->action_data['title'])->toBe('Solo 🚀')
        ->and($pending->action_data['_record_id'])->toBe((string) $note->getKey())
        ->and($pending->display_data['summary'])->toBe('Update note "Solo"');
});

it('updates each approved item and leaves skipped ones untouched, per item', function (): void {
    $notes = Note::factory()->count(3)->for($this->team)->sequence(
        ['title' => 'A'], ['title' => 'B'], ['title' => 'C'],
    )->create();

    proposeNoteUpdates($this->convId, $notes->map(fn (Note $note): array => [
        'id' => (string) $note->getKey(),
        'title' => "{$note->title}!",
    ])->all());

    $pending = latestPending($this->user);
    $service = resolve(PendingActionService::class);

    $service->approveItem($pending, $this->user, 0);
    $service->rejectItem($pending->fresh(), $this->user, 1);
    $last = $service->approveItem($pending->fresh(), $this->user, 2);

    expect($last['finalized'])->toBeTrue()
        ->and($notes[0]->refresh()->title)->toBe('A!')
        ->and($notes[1]->refresh()->title)->toBe('B')
        ->and($notes[2]->refresh()->title)->toBe('C!')
        ->and($pending->fresh()->status)->toBe(PendingActionStatus::Approved)
        ->and($pending->fresh()->result_data['ids'])->toBe([(string) $notes[0]->getKey(), (string) $notes[2]->getKey()]);
});

it('refuses a whole-batch approve so no update can bypass per-item review', function (): void {
    $notes = Note::factory()->count(2)->for($this->team)->create();

    proposeNoteUpdates($this->convId, $notes->map(fn (Note $note): array => ['id' => (string) $note->getKey(), 'title' => 'X'])->all());

    expect(fn () => resolve(PendingActionService::class)->approve(latestPending($this->user), $this->user))
        ->toThrow(RuntimeException::class);
});

it('fails the whole proposal when one record is missing, unowned, or empty', function (): void {
    $mine = Note::factory()->for($this->team)->create(['title' => 'Mine']);
    $other = Note::factory()->for(User::factory()->withPersonalTeam()->create()->currentTeam)->create();

    $missing = proposeNoteUpdates($this->convId, [
        ['id' => (string) $mine->getKey(), 'title' => 'Mine 2'],
        ['id' => (string) $other->getKey(), 'title' => 'Theirs 2'],
    ]);
    $nothing = proposeNoteUpdates($this->convId, [['id' => (string) $mine->getKey()]]);
    $blank = proposeNoteUpdates($this->convId, [['id' => (string) $mine->getKey(), 'title' => '']]);
    $tooMany = proposeNoteUpdates($this->convId, array_fill(0, (int) config('chat.max_batch_size') + 1, ['id' => (string) $mine->getKey(), 'title' => 'x']));

    expect($missing['error'])->toContain('records[1]')->toContain('not found')
        ->and($nothing['error'])->toContain('Nothing to update')
        ->and($blank['error'])->toContain('cannot be empty')
        ->and($tooMany['error'])->toContain('Too many records')
        ->and(PendingAction::query()->count())->toBe(0);
});

it('shows what a re-linked set gives up: old linked names next to the new ones', function (): void {
    $task = Task::factory()->for($this->team)->create(['title' => 'Call']);
    $alice = People::factory()->for($this->team)->create(['name' => 'Alice']);
    $bob = People::factory()->for($this->team)->create(['name' => 'Bob']);
    $task->people()->sync([$alice->getKey()]);

    $tool = resolve(UpdateTaskTool::class);
    $tool->setConversationId($this->convId);
    $tool->handle(new Request(['records' => [['id' => (string) $task->getKey(), 'people_ids' => [(string) $bob->getKey()]]]]));

    $row = collect(latestPending($this->user)->display_data['fields'])->firstWhere('label', 'Linked people');

    expect($row)->toEqual(['label' => 'Linked people', 'old' => 'Alice', 'new' => 'Bob']);
});

it('clears a nullable link when null is passed and shows the clearing on the card', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Acme']);
    $person = People::factory()->for($this->team)->create(['name' => 'Jane', 'company_id' => $company->getKey()]);

    $tool = resolve(UpdatePersonTool::class);
    $tool->setConversationId($this->convId);
    $tool->handle(new Request(['records' => [['id' => (string) $person->getKey(), 'company_id' => null]]]));

    $pending = latestPending($this->user);

    expect($pending->action_data)->toHaveKey('company_id', null)
        ->and(collect($pending->display_data['fields'])->firstWhere('label', 'Company'))->toEqual(['label' => 'Company', 'old' => 'Acme', 'new' => '(none)']);

    resolve(PendingActionService::class)->approve($pending, $this->user);

    expect($person->refresh()->company_id)->toBeNull();
});

it('rejects a record reference from another workspace at proposal time, before any card exists', function (): void {
    $person = People::factory()->for($this->team)->create(['name' => 'Jane']);
    $foreignCompany = Company::factory()->for(User::factory()->withPersonalTeam()->create()->currentTeam)->create();

    $tool = resolve(UpdatePersonTool::class);
    $tool->setConversationId($this->convId);
    $result = json_decode($tool->handle(new Request(['records' => [['id' => (string) $person->getKey(), 'company_id' => (string) $foreignCompany->getKey()]]])), true);

    expect($result['error'])->toContain('company_id')->toContain('not in your workspace')
        ->and(PendingAction::query()->count())->toBe(0);
});

it('refuses a re-proposed update whose values already match the record', function (): void {
    $note = Note::factory()->for($this->team)->create(['title' => 'Same']);

    $result = proposeNoteUpdates($this->convId, [['id' => (string) $note->getKey(), 'title' => 'Same']]);

    expect($result['error'])->toContain('Already up to date')
        ->and(PendingAction::query()->count())->toBe(0);
});

it('treats records with identical display labels as different when their ids differ', function (): void {
    $acmeOne = Company::factory()->for($this->team)->create(['name' => 'Acme']);
    $acmeTwo = Company::factory()->for($this->team)->create(['name' => 'Acme']);
    $person = People::factory()->for($this->team)->create(['name' => 'Jane', 'company_id' => $acmeOne->getKey()]);

    $tool = resolve(UpdatePersonTool::class);
    $tool->setConversationId($this->convId);
    $result = json_decode($tool->handle(new Request(['records' => [['id' => (string) $person->getKey(), 'company_id' => (string) $acmeTwo->getKey()]]])), true);

    $pending = latestPending($this->user);
    $companyRow = collect($pending->display_data['fields'])->firstWhere('label', 'Company');

    expect($result['type'])->toBe('pending_action')
        ->and($companyRow)->not->toHaveKey('_oldValue')
        ->and($companyRow['old'])->toBe('Acme')
        ->and($companyRow['new'])->toBe('Acme');

    resolve(PendingActionService::class)->approve($pending, $this->user);

    expect($person->refresh()->company_id)->toBe($acmeTwo->getKey());
});

it('still refuses an update whose raw values already match, and ignores link order', function (): void {
    $task = Task::factory()->for($this->team)->create(['title' => 'Call']);
    $alice = People::factory()->for($this->team)->create(['name' => 'Alice']);
    $bob = People::factory()->for($this->team)->create(['name' => 'Bob']);
    $task->people()->sync([$alice->getKey(), $bob->getKey()]);

    $tool = resolve(UpdateTaskTool::class);
    $tool->setConversationId($this->convId);
    $result = json_decode($tool->handle(new Request(['records' => [[
        'id' => (string) $task->getKey(),
        'people_ids' => [(string) $bob->getKey(), (string) $alice->getKey()],
    ]]])), true);

    expect($result['error'])->toContain('Already up to date')
        ->and(PendingAction::query()->count())->toBe(0);
});
