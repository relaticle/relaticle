<?php

declare(strict_types=1);

namespace Relaticle\Chat\Livewire\App\Chat;

use App\Enums\Plan;
use App\Features\Billing;
use App\Filament\Pages\Billing as BillingPage;
use App\Livewire\BaseLivewireComponent;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\CreditPackCatalog;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Computed;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\ChatContextService;

final class ChatSidePanel extends BaseLivewireComponent
{
    public bool $isOpen = false;

    public ?string $conversationId = null;

    public ?string $recordType = null;

    public ?string $recordId = null;

    public ?string $recordName = null;

    /**
     * Prompts for the chat interface's empty state. Computed regardless of panel
     * state, because the interface renders them the moment the panel opens
     * rather than on the next navigation.
     *
     * @var array<int, array{label: string, prompt: string}>
     */
    public array $starterPrompts = [];

    /**
     * @var array<string, string>
     */
    protected $listeners = [
        'chat:open-panel' => 'openPanel',
        'chat:close-panel' => 'closePanel',
        'chat:toggle-panel' => 'togglePanel',
    ];

    public function mount(): void
    {
        $this->refreshContext(request()->fullUrl());
    }

    public function openPanel(?string $conversationId = null): void
    {
        $this->isOpen = true;

        if ($conversationId !== null) {
            $this->conversationId = $conversationId;
        }
    }

    public function closePanel(): void
    {
        $this->isOpen = false;
    }

    public function togglePanel(): void
    {
        $this->isOpen = ! $this->isOpen;
    }

    /**
     * Called when the dashboard hero input sends a message.
     * Opens the panel and forwards the message to the embedded chat.
     */
    public function handleSendFromDashboard(string $message, string $source = 'dashboard'): void
    {
        $this->isOpen = true;
        $this->dispatch('chat:send-message', message: $message);
    }

    /**
     * Resolve context for a URL supplied by the browser.
     *
     * Null means "no URL available" (a direct call outside a page context),
     * which clears the binding rather than guessing.
     */
    public function refreshContext(?string $url = null): void
    {
        $contextService = resolve(ChatContextService::class);

        $context = $url === null
            ? ['record_type' => null, 'record_id' => null, 'record_name' => null]
            : $contextService->getContextForUrl($url);

        $this->recordType = $context['record_type'];
        $this->recordId = $context['record_id'];
        $this->recordName = $context['record_name'];
        $this->starterPrompts = $contextService->getSuggestedPrompts($context);

        $this->dispatch(
            'chat:context-updated',
            type: $this->recordType,
            id: $this->recordId,
            label: $this->recordName,
            prompts: $this->starterPrompts,
        );
    }

    #[Computed]
    public function plan(): Plan
    {
        /** @var User|null $user */
        $user = auth()->user();
        $team = $user?->currentTeam;

        return $team !== null ? $team->plan : Plan::default();
    }

    /**
     * Whether to offer a prepaid top-up: a paid plan, billing live, and at
     * least one pack with a configured Stripe price. Without the last check the
     * link lands on a billing page that has nothing to sell.
     */
    #[Computed]
    public function canBuyCredits(): bool
    {
        return $this->plan() !== Plan::Free
            && Feature::active(Billing::class)
            && resolve(CreditPackCatalog::class)->hasPurchasable();
    }

    /**
     * Billing page URL for the current workspace, or null when there is no
     * tenant or billing is switched off. Resolved through the panel route so it
     * holds for both a path-prefixed and a subdomain-routed app panel.
     */
    #[Computed]
    public function billingUrl(): ?string
    {
        $team = Filament::getTenant();

        if (! $team instanceof Team || ! Feature::active(Billing::class)) {
            return null;
        }

        return BillingPage::getUrl(panel: 'app', tenant: $team);
    }

    #[Computed]
    public function creditsRemaining(): int
    {
        /** @var User|null $user */
        $user = auth()->user();
        $teamId = $user?->currentTeam?->getKey();

        if ($teamId === null) {
            return 0;
        }

        return AiCreditBalance::query()
            ->where('team_id', $teamId)
            ->value('credits_remaining') ?? 0;
    }

    public function render(): View
    {
        return view('chat::livewire.app.chat.chat-side-panel');
    }
}
