<?php

declare(strict_types=1);

namespace Relaticle\Chat\Livewire\Chat;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Relaticle\Chat\Actions\OpenEmailRelaConversation;
use Relaticle\Chat\Actions\StartEmailReview;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Jobs\SummarizeEmailConversationJob;
use Relaticle\Chat\Services\ModelRegistry;
use Relaticle\Chat\Support\TurnPresence;
use Relaticle\EmailIntegration\Models\AiSummary;
use Relaticle\EmailIntegration\Models\EmailConversation;
use Relaticle\EmailIntegration\Queries\ConversationFactsQuery;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;
use Relaticle\EmailIntegration\Services\EmailThreadSummaryService;

final class EmailRelaSlot extends Component
{
    #[Locked]
    public string $conversationKey;

    #[Locked]
    public bool $autoSummary = false;

    #[Locked]
    public ?string $conversationId = null;

    public string $summaryState = 'idle';

    public bool $reviewing = false;

    public function mount(): void
    {
        if ($this->available()) {
            $this->conversationId = resolve(OpenEmailRelaConversation::class)->execute($this->viewer(), $this->conversationKey)->id;
            $this->reviewing = TurnPresence::current($this->conversationId) !== null;
        }
        if ($this->autoSummary && resolve(ConversationsQuery::class)->messages($this->viewer(), $this->conversationKey)->count() >= 3) {
            $this->summarize();
        }
    }

    #[Computed]
    public function summary(): ?AiSummary
    {
        $viewer = $this->viewer();
        $conversation = EmailConversation::query()->where('workspace_id', $viewer->current_workspace_id)->where('conversation_key', $this->conversationKey)->first();
        if ($conversation === null) {
            return null;
        }
        $service = resolve(EmailThreadSummaryService::class);

        return $service->cached($viewer, $conversation, hash('sha256', $service->prompt($viewer, $this->conversationKey)));
    }

    #[On('rela:summarize')]
    public function summarize(): void
    {
        if (! $this->available() || $this->summary() instanceof AiSummary) {
            return;
        }
        $viewer = $this->viewer();
        abort_if(resolve(ConversationsQuery::class)->messages($viewer, $this->conversationKey)->isEmpty(), 404);
        $job = new SummarizeEmailConversationJob($viewer->getKey(), $viewer->current_workspace_id, $this->conversationKey);
        $key = 'email-summary:status:'.$job->uniqueId();
        if (Cache::get($key) !== 'working') {
            Cache::put($key, 'working', 120);
            dispatch($job)->afterCommit();
        }
        $this->summaryState = 'working';
    }

    public function suggest(): void
    {
        abort_unless($this->available(), 422);
        $conversation = resolve(StartEmailReview::class)->execute($this->viewer(), $this->conversationKey);
        if ($conversation === null) {
            $this->summaryState = 'no_credits';

            return;
        }
        $this->conversationId = $conversation->id;
        $this->reviewing = true;
    }

    public function check(): void
    {
        if ($this->summaryState === 'working') {
            $job = new SummarizeEmailConversationJob($this->viewer()->getKey(), $this->viewer()->current_workspace_id, $this->conversationKey);
            $state = Cache::get('email-summary:status:'.$job->uniqueId());
            $this->summaryState = is_string($state) ? $state : 'failed';
        }
        if ($this->conversationId !== null) {
            $this->reviewing = TurnPresence::current($this->conversationId) !== null;
        }
    }

    private function available(): bool
    {
        return resolve(ModelRegistry::class)->available() !== [];
    }

    private function viewer(): User
    {
        /** @var User $viewer */
        $viewer = auth()->user();

        return $viewer;
    }

    public function render(): View
    {
        $viewer = $this->viewer();
        $facts = resolve(ConversationFactsQuery::class)->facts($viewer, $this->conversationKey);
        abort_if($facts['count'] === 0, 404);
        $conversation = $this->conversationId === null ? null : resolve(OpenEmailRelaConversation::class)->execute($viewer, $this->conversationKey);
        $plans = $conversation?->pendingActions()->where('status', PendingActionStatus::Pending)->oldest()->get()->unique('turn_id') ?? collect();
        $current = $conversation !== null && $conversation->email_input_hash === hash('sha256', resolve(EmailThreadSummaryService::class)->prompt($viewer, $this->conversationKey));
        $lastReply = $current ? $conversation->messages()->where('role', 'assistant')->latest('created_at')->first()?->content : null;

        return view('chat::livewire.chat.email-rela-slot', ['facts' => $facts, 'available' => $this->available(), 'plans' => $plans, 'lastReply' => $lastReply]);
    }
}
