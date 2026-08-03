<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Relaticle\Chat\Livewire\App\Chat\ChatSidePanel;
use Relaticle\Chat\Services\ChatContextService;

mutates(ChatSidePanel::class);

/**
 * `ChatContextService` derives its context from the current HTTP route, which
 * Livewire's test harness always replaces with its own internal update
 * endpoint — so the real service can never see a fake "current page" here.
 * Swapping the container binding lets these tests target the one thing under
 * test: whether `ChatSidePanel::refreshContext()` copies whatever the context
 * service returns onto the component regardless of `isOpen`.
 */
function bindFakeChatContext(?string $recordType, ?string $recordId, ?string $recordName = 'Acme'): void
{
    app()->instance(ChatContextService::class, new class($recordType, $recordId, $recordName)
    {
        public function __construct(
            private readonly ?string $recordType,
            private readonly ?string $recordId,
            private readonly ?string $recordName,
        ) {}

        /** @return array{page: string|null, record_type: string|null, record_id: string|null, record_name: string|null} */
        public function getContext(): array
        {
            return [
                'page' => 'filament.app.resources.companies.view',
                'record_type' => $this->recordType,
                'record_id' => $this->recordId,
                'record_name' => $this->recordName,
            ];
        }

        /**
         * Delegates to the real service: only getContext() needs faking (it reads the
         * route), while prompt shaping is a pure function of the context array.
         *
         * @param  array{page: string|null, record_type: string|null, record_id: string|null, record_name: string|null}  $context
         * @return array<int, array{label: string, prompt: string}>
         */
        public function getSuggestedPrompts(array $context): array
        {
            return new ChatContextService()->getSuggestedPrompts($context);
        }
    });
}

it('populates recordType and recordId on refreshContext while the panel is closed', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    bindFakeChatContext('company', 'acme-123');

    Livewire::test(ChatSidePanel::class)
        ->set('isOpen', false)
        ->call('refreshContext')
        ->assertSet('isOpen', false)
        ->assertSet('recordType', 'company')
        ->assertSet('recordId', 'acme-123')
        ->assertSet('recordName', 'Acme');
});

it('offers record-aware starter prompts naming the bound record', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    bindFakeChatContext('people', 'person-123', 'Manch Minasyan');

    $component = Livewire::test(ChatSidePanel::class)
        ->set('isOpen', false)
        ->call('refreshContext');

    /** @var array<int, array{label: string, prompt: string}> $prompts */
    $prompts = $component->get('starterPrompts');

    expect(array_column($prompts, 'label'))->toContain('Summarize Manch Minasyan')
        ->and(array_column($prompts, 'prompt'))->toContain('Summarize the contact Manch Minasyan');
});

it('falls back to generic starter prompts when no record is bound', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    bindFakeChatContext(null, null, null);

    $component = Livewire::test(ChatSidePanel::class)
        ->set('isOpen', false)
        ->call('refreshContext');

    /** @var array<int, array{label: string, prompt: string}> $prompts */
    $prompts = $component->get('starterPrompts');

    expect($prompts)->not->toBeEmpty()
        ->and(implode(' ', array_column($prompts, 'label')))->not->toContain('Summarize ');
});

it('clears recordType and recordId on refreshContext when no record is bound', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    bindFakeChatContext(null, null);

    Livewire::test(ChatSidePanel::class)
        ->set('isOpen', false)
        ->call('refreshContext')
        ->assertSet('isOpen', false)
        ->assertSet('recordType', null)
        ->assertSet('recordId', null);
});
