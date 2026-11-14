<?php

declare(strict_types=1);

namespace Relaticle\Chat\Livewire\Chat;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Relaticle\Chat\Actions\DraftEmailWithRela;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\ModelRegistry;
use Relaticle\EmailIntegration\Queries\ComposedEmailsQuery;

final class EmailRelaDraft extends Component
{
    #[Locked]
    public string $composerId;

    #[Reactive]
    public ?string $currentDraftId = null;

    public string $instruction = '';

    #[Locked]
    public ?string $token = null;

    #[Locked]
    public ?string $draftId = null;

    public string $state = 'idle';

    public function request(): void
    {
        $this->validate(['instruction' => ['required', 'string', 'max:2000']]);
        $this->dispatch('composer:flush-autosave', composerId: $this->composerId);
    }

    #[On('composer:autosaved')]
    public function write(string $composerId, string $draftId, int $draftVersion, DraftEmailWithRela $action): void
    {
        if ($composerId !== $this->composerId || $this->token !== null) {
            return;
        }
        /** @var User $user */
        $user = auth()->user();
        abort_if(resolve(ModelRegistry::class)->available() === [], 422);
        $this->token = $action->execute($user, $draftId, $draftVersion, $this->instruction);
        $this->draftId = $draftId;
        $this->state = 'working';
        $this->dispatch('composer:rela-started', composerId: $this->composerId);
    }

    public function check(): void
    {
        if ($this->token === null) {
            return;
        }
        /** @var User $user */
        $user = auth()->user();
        abort_unless($this->draftId !== null && resolve(ComposedEmailsQuery::class)->draft($user, $this->draftId) !== null, 404);
        $status = Cache::get("email-rela:status:{$this->token}");
        if ($status === 'working') {
            return;
        }
        $this->state = $status === 'done' ? 'done' : 'failed';
        $this->token = null;
        $this->dispatch('composer:rela-finished', composerId: $this->composerId, reload: $status === 'done');
    }

    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();
        $drafted = $this->currentDraftId !== null && AiCreditTransaction::query()->where('workspace_id', $user->current_workspace_id)
            ->where('user_id', $user->getKey())->where('type', AiCreditType::Draft)->where('metadata->email_id', $this->currentDraftId)->exists();

        return view('chat::livewire.chat.email-rela-draft', ['available' => resolve(ModelRegistry::class)->available() !== [], 'drafted' => $drafted]);
    }
}
