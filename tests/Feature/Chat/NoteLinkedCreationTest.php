<?php

declare(strict_types=1);

use App\Actions\Note\CreateNote;
use App\Actions\People\CreatePeople;
use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Support\RecordNameResolver;
use Relaticle\Chat\Tools\Note\CreateNoteTool;

mutates(CreateNoteTool::class);
mutates(CreateNote::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    Auth::guard('web')->setUser($this->user);

    DB::table('agent_conversations')->insert([
        'id' => '019df800-4444-7000-8000-000000000001',
        'participant_type' => 'user',
        'participant_id' => (string) $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('CreateNoteTool wraps entity fields inside a records[] schema', function (): void {
    $tool = resolve(CreateNoteTool::class);
    $schema = $tool->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKey('records');
});

it('persists people_ids in the pending action data', function (): void {
    $angel = People::factory()->for($this->team)->create(['name' => 'Angel']);

    $tool = resolve(CreateNoteTool::class);
    $tool->setConversationId('019df800-4444-7000-8000-000000000001');

    $tool->handle(new Request([
        'records' => [['title' => 'Discovery call notes', 'people_ids' => [(string) $angel->id]]],
    ]));

    $pending = PendingAction::query()
        ->where('team_id', $this->team->getKey())
        ->latest()
        ->firstOrFail();

    expect($pending->action_data)
        ->toHaveKey('title', 'Discovery call notes')
        ->toHaveKey('people_ids', [(string) $angel->id]);
});

it('approving a note with people_ids creates the noteables pivot', function (): void {
    $angel = People::factory()->for($this->team)->create(['name' => 'Angel']);

    $note = resolve(CreateNote::class)->execute(
        $this->user,
        ['title' => 'Discovery call notes', 'people_ids' => [(string) $angel->id]],
        CreationSource::CHAT,
    );

    expect($note)->toBeInstanceOf(Note::class);
    expect($note->people()->pluck('people.id')->all())->toContain((string) $angel->id);
});

it('approving a note with company_ids creates the noteables pivot', function (): void {
    $acme = Company::factory()->for($this->team)->create(['name' => 'Acme']);

    $note = resolve(CreateNote::class)->execute(
        $this->user,
        ['title' => 'Account brief', 'company_ids' => [(string) $acme->id]],
        CreationSource::CHAT,
    );

    expect($note->companies()->pluck('companies.id')->all())->toContain((string) $acme->id);
});

it('approving a note with opportunity_ids creates the noteables pivot', function (): void {
    $deal = Opportunity::factory()->for($this->team)->create(['name' => 'Q3 Renewal']);

    $note = resolve(CreateNote::class)->execute(
        $this->user,
        ['title' => 'Deal review', 'opportunity_ids' => [(string) $deal->id]],
        CreationSource::CHAT,
    );

    expect($note->opportunities()->pluck('opportunities.id')->all())->toContain((string) $deal->id);
});

it('rejects cross-tenant people_ids at the action layer', function (): void {
    $other = User::factory()->withPersonalTeam()->create();
    $foreign = People::factory()->for($other->currentTeam)->create(['name' => 'Mallory']);

    expect(fn () => resolve(CreateNote::class)->execute(
        $this->user,
        ['title' => 'X', 'people_ids' => [(string) $foreign->id]],
        CreationSource::CHAT,
    ))->toThrow(ValidationException::class);
});

it('rejects cross-tenant company_ids at the action layer', function (): void {
    $other = User::factory()->withPersonalTeam()->create();
    $foreign = Company::factory()->for($other->currentTeam)->create(['name' => 'EvilCorp']);

    expect(fn () => resolve(CreateNote::class)->execute(
        $this->user,
        ['title' => 'X', 'company_ids' => [(string) $foreign->id]],
        CreationSource::CHAT,
    ))->toThrow(ValidationException::class);
});

it('renders linked names in the proposal display data', function (): void {
    $angel = People::factory()->for($this->team)->create(['name' => 'Angel']);
    $acme = Company::factory()->for($this->team)->create(['name' => 'Acme']);

    $tool = resolve(CreateNoteTool::class);
    $tool->setConversationId('019df800-4444-7000-8000-000000000001');

    $tool->handle(new Request([
        'records' => [['title' => 'Discovery', 'people_ids' => [(string) $angel->id], 'company_ids' => [(string) $acme->id]]],
    ]));

    $pending = PendingAction::query()
        ->where('team_id', $this->team->getKey())
        ->latest()
        ->firstOrFail();

    $fields = collect($pending->display_data['fields'] ?? []);
    expect($fields->pluck('label')->all())->toContain('Linked people', 'Linked companies');
    expect($fields->firstWhere('label', 'Linked people')['value'] ?? '')->toContain('Angel');
    expect($fields->firstWhere('label', 'Linked companies')['value'] ?? '')->toContain('Acme');
});

it('coerces a scalar people_ids into a list instead of dropping it', function (): void {
    $angel = People::factory()->for($this->team)->create(['name' => 'Angel']);

    $tool = resolve(CreateNoteTool::class);
    $tool->setConversationId('019df800-4444-7000-8000-000000000001');

    $tool->handle(new Request([
        'records' => [['title' => 'Scalar people', 'people_ids' => (string) $angel->id]],
    ]));

    $pending = PendingAction::query()
        ->where('team_id', $this->team->getKey())
        ->latest()
        ->firstOrFail();

    expect($pending->action_data)->toHaveKey('people_ids', [(string) $angel->id]);
});

it('resolves a whole list of linked names in one query, in the order given', function (): void {
    $people = collect(['Ada', 'Grace', 'Katherine', 'Dorothy'])
        ->map(fn (string $name): People => People::factory()->for($this->team)->create(['name' => $name]));

    $resolver = resolve(RecordNameResolver::class);
    $ids = $people->map(fn (People $p): string => (string) $p->id)->all();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $names = $resolver->names($ids, People::class, $this->team);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($names)->toBe('Ada, Grace, Katherine, Dorothy')
        ->and($queries)->toHaveCount(1, 'one lookup per id is an N+1 on every proposal card');
});

it('still labels a plan reference while batching the stored ids beside it', function (): void {
    $ada = People::factory()->for($this->team)->create(['name' => 'Ada']);
    $grace = People::factory()->for($this->team)->create(['name' => 'Grace']);

    $referenced = PendingAction::query()->create([
        'team_id' => $this->team->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => '019df800-4444-7000-8000-000000000001',
        'action_class' => CreatePeople::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'people',
        'action_data' => ['name' => 'Katherine'],
        'display_data' => ['title' => 'Create person', 'summary' => 'Create Katherine'],
        'status' => PendingActionStatus::Pending,
        'turn_id' => (string) Str::ulid(),
        'expires_at' => now()->addMinutes(15),
    ]);

    $names = resolve(RecordNameResolver::class)->names(
        [(string) $ada->id, '$ref:'.$referenced->getKey(), (string) $grace->id],
        People::class,
        $this->team,
    );

    expect($names)->toStartWith('Ada, Katherine')
        ->and($names)->toEndWith('Grace')
        ->and($names)->toContain('step 1');
});
