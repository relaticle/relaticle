<?php

declare(strict_types=1);

use App\Actions\Company\UpdateCompany;
use App\Actions\Note\UpdateNote;
use App\Actions\Opportunity\UpdateOpportunity;
use App\Actions\People\UpdatePeople;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Tools\Company\UpdateCompanyTool;
use Relaticle\Chat\Tools\Note\UpdateNoteTool;
use Relaticle\Chat\Tools\Opportunity\UpdateOpportunityTool;
use Relaticle\Chat\Tools\People\UpdatePersonTool;

mutates(UpdateCompanyTool::class);
mutates(UpdateCompany::class);
mutates(UpdateNoteTool::class);
mutates(UpdateNote::class);
mutates(UpdateOpportunityTool::class);
mutates(UpdateOpportunity::class);
mutates(UpdatePersonTool::class);
mutates(UpdatePeople::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    Auth::guard('web')->setUser($this->user);

    DB::table('agent_conversations')->insert([
        'id' => '019df800-3333-7000-8000-000000000099',
        'user_id' => (string) $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('UpdateCompanyTool proposes a name change and approval persists it', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Old Co']);

    $tool = resolve(UpdateCompanyTool::class);
    $tool->setConversationId('019df800-3333-7000-8000-000000000099');

    $tool->handle(new Request([
        'id' => (string) $company->id,
        'name' => 'New Co',
    ]));

    $pending = PendingAction::query()
        ->where('team_id', $this->team->getKey())
        ->latest()
        ->firstOrFail();

    expect($pending->action_data)->toHaveKey('name', 'New Co');

    resolve(UpdateCompany::class)->execute($this->user, $company, $pending->action_data);

    expect($company->refresh()->name)->toBe('New Co');
});

it('UpdateNoteTool proposes a title change and approval persists it', function (): void {
    $note = Note::factory()->for($this->team)->create(['title' => 'Old title']);

    $tool = resolve(UpdateNoteTool::class);
    $tool->setConversationId('019df800-3333-7000-8000-000000000099');

    $tool->handle(new Request([
        'id' => (string) $note->id,
        'title' => 'New title',
    ]));

    $pending = PendingAction::query()
        ->where('team_id', $this->team->getKey())
        ->latest()
        ->firstOrFail();

    expect($pending->action_data)->toHaveKey('title', 'New title');

    resolve(UpdateNote::class)->execute($this->user, $note, $pending->action_data);

    expect($note->refresh()->title)->toBe('New title');
});

it('UpdateNoteTool can resync linked people via people_ids', function (): void {
    $note = Note::factory()->for($this->team)->create(['title' => 'Note']);
    $alice = People::factory()->for($this->team)->create(['name' => 'Alice']);

    $tool = resolve(UpdateNoteTool::class);
    $tool->setConversationId('019df800-3333-7000-8000-000000000099');

    $tool->handle(new Request([
        'id' => (string) $note->id,
        'people_ids' => [(string) $alice->id],
    ]));

    $pending = PendingAction::query()
        ->where('team_id', $this->team->getKey())
        ->latest()
        ->firstOrFail();

    expect($pending->action_data)->toHaveKey('people_ids', [(string) $alice->id]);

    resolve(UpdateNote::class)->execute($this->user, $note, $pending->action_data);

    expect($note->refresh()->people()->pluck('people.id')->all())
        ->toContain((string) $alice->id);
});

it('coerces a scalar people id into a single-element list on note update', function (): void {
    $note = Note::factory()->for($this->team)->create(['title' => 'Note']);
    $person = People::factory()->for($this->team)->create(['name' => 'Jane']);

    $tool = new UpdateNoteTool;

    $request = new Request([
        'id' => (string) $note->id,
        'people_ids' => (string) $person->id,
    ]);

    $data = (fn (): array => $this->extractActionData($request))->call($tool);

    expect($data['people_ids'] ?? null)->toBe([(string) $person->id]);
});

it('UpdateOpportunityTool proposes a name change and approval persists it', function (): void {
    $opportunity = Opportunity::factory()->for($this->team)->create(['name' => 'Old deal']);

    $tool = resolve(UpdateOpportunityTool::class);
    $tool->setConversationId('019df800-3333-7000-8000-000000000099');

    $tool->handle(new Request([
        'id' => (string) $opportunity->id,
        'name' => 'New deal',
    ]));

    $pending = PendingAction::query()
        ->where('team_id', $this->team->getKey())
        ->latest()
        ->firstOrFail();

    expect($pending->action_data)->toHaveKey('name', 'New deal');

    resolve(UpdateOpportunity::class)->execute($this->user, $opportunity, $pending->action_data);

    expect($opportunity->refresh()->name)->toBe('New deal');
});

it('UpdateOpportunityTool can repoint contact_id and persist it', function (): void {
    $opportunity = Opportunity::factory()->for($this->team)->create(['name' => 'Deal']);
    $contact = People::factory()->for($this->team)->create(['name' => 'Contact A']);

    $tool = resolve(UpdateOpportunityTool::class);
    $tool->setConversationId('019df800-3333-7000-8000-000000000099');

    $tool->handle(new Request([
        'id' => (string) $opportunity->id,
        'contact_id' => (string) $contact->id,
    ]));

    $pending = PendingAction::query()
        ->where('team_id', $this->team->getKey())
        ->latest()
        ->firstOrFail();

    expect($pending->action_data)->toHaveKey('contact_id', (string) $contact->id);

    resolve(UpdateOpportunity::class)->execute($this->user, $opportunity, $pending->action_data);

    expect($opportunity->refresh()->contact_id)->toBe((string) $contact->id);
});

it('UpdatePersonTool proposes a name change and approval persists it', function (): void {
    $person = People::factory()->for($this->team)->create(['name' => 'Old name']);

    $tool = resolve(UpdatePersonTool::class);
    $tool->setConversationId('019df800-3333-7000-8000-000000000099');

    $tool->handle(new Request([
        'id' => (string) $person->id,
        'name' => 'New name',
    ]));

    $pending = PendingAction::query()
        ->where('team_id', $this->team->getKey())
        ->latest()
        ->firstOrFail();

    expect($pending->action_data)->toHaveKey('name', 'New name');

    resolve(UpdatePeople::class)->execute($this->user, $person, $pending->action_data);

    expect($person->refresh()->name)->toBe('New name');
});

it('UpdatePersonTool can repoint company_id and persist it', function (): void {
    $person = People::factory()->for($this->team)->create(['name' => 'Person']);
    $newCompany = Company::factory()->for($this->team)->create(['name' => 'NewCo']);

    $tool = resolve(UpdatePersonTool::class);
    $tool->setConversationId('019df800-3333-7000-8000-000000000099');

    $tool->handle(new Request([
        'id' => (string) $person->id,
        'company_id' => (string) $newCompany->id,
    ]));

    $pending = PendingAction::query()
        ->where('team_id', $this->team->getKey())
        ->latest()
        ->firstOrFail();

    expect($pending->action_data)->toHaveKey('company_id', (string) $newCompany->id);

    resolve(UpdatePeople::class)->execute($this->user, $person, $pending->action_data);

    expect($person->refresh()->company_id)->toBe((string) $newCompany->id);
});

it('UpdateCompanyTool proposes an account owner change with names in the display and approval persists it', function (): void {
    $teammate = User::factory()->create(['name' => 'Alex Owner']);
    $this->team->users()->attach($teammate, ['role' => 'editor']);
    $company = Company::factory()->for($this->team)->create(['name' => 'Owned Co']);

    $tool = resolve(UpdateCompanyTool::class);
    $tool->setConversationId('019df800-3333-7000-8000-000000000099');

    $response = $tool->handle(new Request([
        'id' => (string) $company->id,
        'account_owner_id' => (string) $teammate->getKey(),
    ]));

    expect($response)->toContain('pending_action');

    $pending = PendingAction::query()
        ->where('team_id', $this->team->getKey())
        ->latest()
        ->firstOrFail();

    expect($pending->action_data)->toHaveKey('account_owner_id', (string) $teammate->getKey());

    $ownerRow = collect($pending->display_data['fields'])->firstWhere('label', 'Account Owner');
    expect($ownerRow)->not->toBeNull()
        ->and($ownerRow['new'])->toBe('Alex Owner');

    resolve(UpdateCompany::class)->execute($this->user, $company, $pending->action_data);

    expect($company->refresh()->account_owner_id)->toBe((string) $teammate->getKey());
});

it('UpdateCompanyTool rejects a non-member account_owner_id without creating a proposal', function (): void {
    $stranger = User::factory()->withPersonalTeam()->create(['name' => 'Foreign Frank']);
    $company = Company::factory()->for($this->team)->create(['name' => 'Guarded Co']);

    $tool = resolve(UpdateCompanyTool::class);
    $tool->setConversationId('019df800-3333-7000-8000-000000000099');

    $response = $tool->handle(new Request([
        'id' => (string) $company->id,
        'account_owner_id' => (string) $stranger->getKey(),
    ]));

    expect($response)->toContain('must be a workspace team member')
        ->and(PendingAction::query()->where('team_id', $this->team->getKey())->count())->toBe(0);
});

it('UpdateCompanyTool unassigns the account owner when an empty string is passed', function (): void {
    $company = Company::factory()->for($this->team)->create([
        'name' => 'Unowned Co',
        'account_owner_id' => (string) $this->user->getKey(),
    ]);

    $tool = resolve(UpdateCompanyTool::class);
    $tool->setConversationId('019df800-3333-7000-8000-000000000099');

    $tool->handle(new Request([
        'id' => (string) $company->id,
        'account_owner_id' => '',
    ]));

    $pending = PendingAction::query()
        ->where('team_id', $this->team->getKey())
        ->latest()
        ->firstOrFail();

    expect($pending->action_data)->toHaveKey('account_owner_id')
        ->and($pending->action_data['account_owner_id'])->toBeNull();

    resolve(UpdateCompany::class)->execute($this->user, $company, $pending->action_data);

    expect($company->refresh()->account_owner_id)->toBeNull();
});

it('UpdateCompany action rejects an account_owner_id outside the workspace', function (): void {
    $stranger = User::factory()->withPersonalTeam()->create();
    $company = Company::factory()->for($this->team)->create(['name' => 'Tenant Safe Co']);

    expect(fn () => resolve(UpdateCompany::class)->execute($this->user, $company, [
        'account_owner_id' => (string) $stranger->getKey(),
    ]))->toThrow(ValidationException::class);
});
