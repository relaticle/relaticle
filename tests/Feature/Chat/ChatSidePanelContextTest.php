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
function bindFakeChatContext(?string $recordType, ?string $recordId): void
{
    app()->instance(ChatContextService::class, new class($recordType, $recordId)
    {
        public function __construct(
            private readonly ?string $recordType,
            private readonly ?string $recordId,
        ) {}

        /** @return array{page: string|null, record_type: string|null, record_id: string|null, record_name: string|null} */
        public function getContext(): array
        {
            return [
                'page' => 'filament.app.resources.companies.view',
                'record_type' => $this->recordType,
                'record_id' => $this->recordId,
                'record_name' => 'Acme',
            ];
        }

        /**
         * @param  array<string, mixed>  $context
         * @return array<int, array{label: string, prompt: string}>
         */
        public function getSuggestedPrompts(array $context): array
        {
            return [];
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
        ->assertSet('recordId', 'acme-123');
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
