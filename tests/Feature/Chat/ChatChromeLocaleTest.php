<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Task;
use App\Models\User;
use App\Support\Locales;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Laravel\Ai\Tools\Request;
use Livewire\Livewire;
use Relaticle\Chat\Data\ProposalConflict;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Enums\ProposalEntity;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Relaticle\Chat\Livewire\Chat\ProposalCard;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Support\ProposalConflictNotice;
use Relaticle\Chat\Tools\Company\CreateCompanyTool;
use Relaticle\Chat\Tools\Company\UpdateCompanyTool;
use Relaticle\Chat\Tools\Opportunity\CreateOpportunityTool;
use Relaticle\Chat\Tools\People\CreatePersonTool;
use Relaticle\Chat\Tools\Task\CreateTaskTool;
use Relaticle\Chat\Tools\Task\DeleteTaskTool;

mutates(ChatInterface::class, ProposalCard::class);

beforeEach(function (): void {
    Lang::addLines([
        'enums/proposal-entity.create_title.task' => 'Creer une tache',
        'enums/proposal-entity.create_summary.task' => '« :title » a creer',
        'enums/proposal-entity.create_label.task' => 'Tache a creer',
        'enums/proposal-entity.batch_title.create.task' => 'Tache a creer|Taches a creer',
        'enums/proposal-entity.batch_summary.create.task' => ':count tache a creer|:count taches a creer',
        'enums/proposal-entity.delete_title.task' => 'Supprimer la tache',
        'enums/proposal-entity.delete_summary.task' => 'Supprimer la tache « :name »',
        'enums/proposal-entity.plural.task' => 'tache|taches',
        'enums/proposal-entity.noun.task' => 'Tache',
    ], 'fr');

    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentWorkspace);
});

it('renders the chat strings in the user language', function (): void {
    Locales::apply('fr');

    $html = preg_replace('/\\\\+u/', '\\u', html_entity_decode(Livewire::test(ChatInterface::class)->html()));
    $inScript = fn (string $key): string => trim(json_encode(Lang::get($key, [], 'fr'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), '"');

    expect($html)
        ->toContain($inScript('Ask anything...'))
        ->toContain($inScript('Searching companies…'))
        ->toContain($inScript('Reading company details…'))
        ->toContain($inScript('New chat'))
        ->toContain($inScript('Your session expired. Please sign in again: your message is saved locally.'));
});

function proposeFrenchTasks(object $test, array $titles): PendingAction
{
    $conversationId = '019df900-4444-7000-8000-0000000000aa';
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $test->user->getKey(),
        'workspace_id' => $test->user->currentWorkspace->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Locales::within('fr', function () use ($conversationId, $titles): void {
        $tool = resolve(CreateTaskTool::class);
        $tool->setConversationId($conversationId);
        $tool->handle(new Request(['records' => array_map(fn (string $title): array => ['title' => $title], $titles)]));
    });

    return PendingAction::query()->where('conversation_id', $conversationId)->firstOrFail();
}

it('names a single record from its data when the summary uses guillemets', function (): void {
    $pending = proposeFrenchTasks($this, ['Appeler Priya']);

    $view = Locales::within('fr', fn (): array => Livewire::test(ProposalCard::class, ['context' => 'conversation'])->dispatch('proposal:set-active', id: $pending->getKey(), context: 'conversation')->instance()->stepViews()[0]);

    expect($pending->display_data['summary'])->toBe('« Appeler Priya » a creer')
        ->and($view['recordLabel'])->toBe('Appeler Priya')
        ->and($view['title'])->toBe('Creer une tache');
});

it('builds batch titles, row names and nouns in the user language', function (): void {
    $pending = proposeFrenchTasks($this, ['Appeler Priya', 'Envoyer le devis']);

    $view = Locales::within('fr', fn (): array => Livewire::test(ProposalCard::class, ['context' => 'conversation'])->dispatch('proposal:set-active', id: $pending->getKey(), context: 'conversation')->instance()->stepViews()[0]);

    expect($pending->display_data['title'])->toBe('Taches a creer')
        ->and($pending->display_data['summary'])->toBe('2 taches a creer')
        ->and(array_column($view['items'], 'label'))->toBe(['Appeler Priya', 'Envoyer le devis'])
        ->and($view['entityNoun'])->toBe('Tache')
        ->and($view['createLabel'])->toBe('Tache a creer');
});

it('builds a single delete title and summary from full-phrase keys', function (): void {
    $task = Task::factory()->for($this->user->currentWorkspace)->create(['title' => 'Appeler Priya']);

    Locales::within('fr', fn (): string => resolve(DeleteTaskTool::class)->handle(new Request(['ids' => [$task->getKey()]])));

    $pending = PendingAction::query()->where('user_id', $this->user->getKey())->firstOrFail();

    expect($pending->display_data['title'])->toBe('Supprimer la tache')
        ->and($pending->display_data['summary'])->toBe('Supprimer la tache « Appeler Priya »');
});

it('stores the approval error in English and shows it in the user language', function (): void {
    Bus::fake();

    $action = PendingAction::query()->create([
        'workspace_id' => $this->user->currentWorkspace->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => null,
        'action_class' => 'App\\Actions\\Company\\NotAnAllowedAction',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'company',
        'action_data' => ['name' => 'Acme'],
        'display_data' => ['title' => 'Create company', 'summary' => 'Create company "Acme"', 'fields' => []],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    Locales::apply('fr');

    $component = Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('createCurrent')
        ->assertDispatched('proposal:resolve-failed');

    expect($component->errors()->first('resolve'))->toContain(Lang::get('Action class not allowlisted', [], 'fr'))
        ->and($action->fresh()->result_data['last_error'])->toContain('Action class not allowlisted')
        ->and($action->fresh()->result_data['last_error'])->not->toContain('Action non');
});

function unapprovablePendingAction(User $user): PendingAction
{
    return PendingAction::query()->create([
        'workspace_id' => $user->currentWorkspace->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => null,
        'action_class' => 'App\\Actions\\Company\\NotAnAllowedAction',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'company',
        'action_data' => ['name' => 'Acme'],
        'display_data' => ['title' => 'Create company', 'summary' => 'Create company "Acme"', 'fields' => []],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);
}

it('shows a German user the approval error in German and stores it in English', function (string $method): void {
    $action = unapprovablePendingAction($this->user);
    Locales::apply('de');

    $component = Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call($method)
        ->assertDispatched('proposal:resolve-failed');

    expect($component->errors()->first('resolve'))->toContain(Lang::get('Action class not allowlisted', [], 'de'))
        ->and($component->errors()->first('resolve'))->not->toContain('Action class not allowlisted')
        ->and($action->fresh()->result_data['last_error'])->toContain('Action class not allowlisted');
})->with(['createCurrent', 'approveAll']);

it('builds batch receipts as whole phrases in the user language', function (): void {
    $receipts = Locales::within('de', fn (): array => ProposalEntity::receipts());

    expect($receipts['task']['batch']['create'])->toBe(['one' => ':count Aufgabe erstellt', 'other' => ':count Aufgaben erstellt'])
        ->and($receipts['opportunity']['batch']['delete'])->toBe(['one' => ':count Verkaufschance gelöscht', 'other' => ':count Verkaufschancen gelöscht'])
        ->and($receipts['email_drafts']['batch'])->toHaveKey('create')->not->toHaveKey('delete');
});

it('words a custom attribute batch receipt in every language', function (string $locale, string $operation, string $one): void {
    Locales::apply($locale);

    $batch = ProposalEntity::receipts()['custom_field']['batch'][$operation];

    expect($batch['one'])->toBe($one)
        ->and($batch['other'])->toContain(':count')->not->toBe($batch['one']);
})->with([
    'en create' => ['en', 'create', 'Created :count custom attribute'],
    'en delete' => ['en', 'delete', 'Deleted :count custom attribute'],
    'de update' => ['de', 'update', ':count benutzerdefiniertes Attribut aktualisiert'],
]);

it('words a proposal conflict in the viewer language with the viewer as subject', function (string $locale, string $causer, string $today, string $earlier): void {
    Locales::apply($locale);
    $this->travelTo('2026-11-18 14:40:00');
    $viewer = User::factory()->create(['timezone' => 'UTC']);
    $conflict = fn (string $at): ProposalConflict => new ProposalConflict('a1', 'amount', 'Amount', '20', $causer === 'you' ? (string) $viewer->getKey() : 'other', 'Maya Chen', CarbonImmutable::parse($at));

    expect(ProposalConflictNotice::for([$conflict('2026-11-18 14:32:00')], $viewer))->toContain($today)
        ->and(ProposalConflictNotice::for([$conflict('2026-11-12 09:05:00')], $viewer))->toContain($earlier);
})->with([
    'fr you' => ['fr', 'you', 'Vous avez défini Amount sur 20 à 14:32', 'Vous avez défini Amount sur 20 le 12 nov. 2026 09:05'],
    'fr other' => ['fr', 'other', 'Maya Chen a défini Amount sur 20 à 14:32', 'Maya Chen a défini Amount sur 20 le 12 nov. 2026 09:05'],
    'de you' => ['de', 'you', 'Sie haben Amount auf 20 gesetzt (um 14:32)', 'Sie haben Amount auf 20 gesetzt (am 12. Nov 2026 09:05)'],
    'en other' => ['en', 'other', 'Maya Chen set Amount to 20 at 2:32 PM', 'Maya Chen set Amount to 20 on Nov 12, 2026 9:05 AM'],
]);

it('labels the rows of a create proposal in the user language', function (): void {
    $conversationId = '019df900-4444-7000-8000-0000000000bb';
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $this->user->getKey(),
        'workspace_id' => $this->user->currentWorkspace->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $company = Company::factory()->for($this->user->currentWorkspace)->create(['name' => 'Acme']);

    $labels = Locales::within('de', function () use ($conversationId, $company): array {
        $propose = function (string $tool, array $record) use ($conversationId): void {
            $instance = resolve($tool);
            $instance->setConversationId($conversationId);
            $instance->handle(new Request(['records' => [$record]]));
        };

        $propose(CreateCompanyTool::class, ['name' => 'Globex', 'account_owner_id' => (string) $this->user->getKey()]);
        $propose(CreatePersonTool::class, ['name' => 'Priya', 'company_id' => (string) $company->getKey()]);
        $propose(CreateOpportunityTool::class, ['name' => 'Renewal', 'company_id' => (string) $company->getKey()]);

        return PendingAction::query()->where('conversation_id', $conversationId)->orderBy('id')->get()
            ->map(fn (PendingAction $action): array => array_column($action->display_data['fields'], 'label'))
            ->all();
    });

    expect($labels)->toBe([
        ['Name', 'Verantwortlicher'],
        ['Name', 'Unternehmen'],
        ['Name', 'Unternehmen'],
    ]);
});

it('titles an update proposal in the user language', function (): void {
    $conversationId = '019df900-4444-7000-8000-0000000000cc';
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $this->user->getKey(),
        'workspace_id' => $this->user->currentWorkspace->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $company = Company::factory()->for($this->user->currentWorkspace)->create(['name' => 'Acme']);

    $display = Locales::within('de', function () use ($conversationId, $company): array {
        $tool = resolve(UpdateCompanyTool::class);
        $tool->setConversationId($conversationId);
        $tool->handle(new Request(['records' => [['id' => (string) $company->getKey(), 'name' => 'Acme Neu']]]));

        return PendingAction::query()->where('conversation_id', $conversationId)->sole()->display_data;
    });

    expect($display['title'])->toBe('Unternehmen aktualisieren')
        ->and($display['summary'])->toBe('Unternehmen „Acme“ aktualisieren');
});
