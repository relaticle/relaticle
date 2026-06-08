<?php

declare(strict_types=1);

use App\Actions\Note\CreateNote;
use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Tools\Note\CreateNoteTool;

mutates(CreateNoteTool::class);
mutates(CreateNote::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    Auth::guard('web')->setUser($this->user);

    DB::table('agent_conversations')->insert([
        'id' => '019df800-4444-7000-8000-000000000001',
        'user_id' => (string) $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('CreateNoteTool exposes people_ids, company_ids, and opportunity_ids in the schema', function (): void {
    $tool = resolve(CreateNoteTool::class);
    $schema = $tool->schema(new JsonSchemaTypeFactory);

    expect($schema)
        ->toHaveKey('title')
        ->toHaveKey('people_ids')
        ->toHaveKey('company_ids')
        ->toHaveKey('opportunity_ids');
});

it('persists people_ids in the pending action data', function (): void {
    $angel = People::factory()->for($this->team)->create(['name' => 'Angel']);

    $tool = resolve(CreateNoteTool::class);
    $tool->setConversationId('019df800-4444-7000-8000-000000000001');

    $tool->handle(new Request([
        'title' => 'Discovery call notes',
        'people_ids' => [(string) $angel->id],
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
        'title' => 'Discovery',
        'people_ids' => [(string) $angel->id],
        'company_ids' => [(string) $acme->id],
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
