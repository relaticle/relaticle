<?php

declare(strict_types=1);

use App\Models\Task;
use App\Models\User;
use App\Support\Locales;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Laravel\Ai\Tools\Request;
use Livewire\Livewire;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Relaticle\Chat\Livewire\Chat\ProposalCard;
use Relaticle\Chat\Models\PendingAction;
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
