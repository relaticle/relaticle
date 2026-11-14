<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use App\Models\User;
use App\Models\Workspace;
use Filament\Pages\Page;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use Relaticle\EmailIntegration\Actions\MarkConversationsDone;
use Relaticle\EmailIntegration\Actions\MarkConversationsRead;
use Relaticle\EmailIntegration\Actions\MarkConversationsUnread;
use Relaticle\EmailIntegration\Actions\ReopenConversations;
use Relaticle\EmailIntegration\Data\ConversationPage;
use Relaticle\EmailIntegration\Data\ConversationRow;
use Relaticle\EmailIntegration\Data\TriageOutcome;
use Relaticle\EmailIntegration\Enums\ConversationView;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPageTab;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Enums\MailboxConnectState;
use Relaticle\EmailIntegration\Enums\OutboxTab;
use Relaticle\EmailIntegration\Enums\SendFailureRemedy;
use Relaticle\EmailIntegration\Filament\Actions\ConnectMailboxAction;
use Relaticle\EmailIntegration\Filament\Concerns\HasConversationFilters;
use Relaticle\EmailIntegration\Filament\Concerns\HasConversationReader;
use Relaticle\EmailIntegration\Filament\Concerns\HasConversationsFlag;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailFeatureFlag;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailReaderActions;
use Relaticle\EmailIntegration\Filament\Concerns\HasUnlinkedSenders;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailTemplate;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;

/**
 * @property-read ConversationView $activeView
 * @property-read array{needs_reply: int, unlinked: int, automated: int} $viewCounts
 * @property-read ConversationPage $conversations
 * @property-read Email|null $selectedEmail
 * @property-read SupportCollection<int, EmailAccessRequest> $pendingAccessRequests
 */
final class EmailInboxPage extends Page
{
    use HasConversationFilters;
    use HasConversationReader;
    use HasConversationsFlag;
    use HasEmailFeatureFlag;
    use HasEmailReaderActions;
    use HasUnlinkedSenders;

    protected string $view = 'email-integration::filament.pages.email-inbox';

    protected static ?string $navigationLabel = null;

    protected static ?string $slug = 'email';

    protected static string|\BackedEnum|null $navigationIcon = 'lucide-mail';

    protected static ?int $navigationSort = 6;

    public static function getNavigationLabel(): string
    {
        return __('filament/pages/email-inbox.navigation_label');
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        $workspace = filament()->getTenant();

        if (! $user instanceof User || ! $workspace instanceof Workspace) {
            return null;
        }

        if (ConnectedAccount::query()->ownedBy($user, $workspace)->connected()->unhealthy()->doesntExist()) {
            return null;
        }

        return __('filament/pages/email-inbox.health.nav_badge');
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return __('filament/pages/email-inbox.health.nav_tooltip');
    }

    public function getTitle(): string
    {
        return __('filament/pages/email-inbox.navigation_label');
    }

    public function getHeadingIcon(): string|\BackedEnum|Htmlable|null
    {
        return self::getNavigationIcon();
    }

    /**
     * Which of the lists (drafts, outbox, templates) the page is showing. The tab
     * bodies are nested Livewire components shared with their standalone pages.
     */
    #[Url(as: 'tab')]
    public ?string $tab = null;

    #[Url(as: 'view')]
    public ?ConversationView $conversationView = null;

    #[Url(as: 'email')]
    public ?string $selectedEmailId = null;

    public ?string $conversationsCursor = null;

    public function mount(): void
    {
        $workspace = filament()->getTenant();

        if ($this->tab === 'failed' && $workspace instanceof Workspace) {
            $this->redirect(SendFailureRemedy::Retry->url($workspace));

            return;
        }

        if (! $this->showsConversations()) {
            $this->tab ??= EmailPageTab::DRAFTS->value;

            return;
        }

        if ($this->selectedEmailId !== null) {
            $this->openLinkedEmail($this->selectedEmailId);
        }
    }

    #[Computed]
    public function activeTab(): ?EmailPageTab
    {
        return $this->tab === null && $this->showsConversations()
            ? null
            : (EmailPageTab::tryFrom($this->tab ?? '') ?? EmailPageTab::DRAFTS);
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return [
            // The composer saves/discards drafts and queues mail from outside this
            // component, so the tab badges have to be told when those counts move.
            'drafts:changed' => 'refreshTabCounts',
            'outbox:changed' => 'refreshTabCounts',
        ];
    }

    public function refreshTabCounts(): void
    {
        unset($this->tabCounts);
    }

    public function setTab(string $tab): void
    {
        $this->tab = EmailPageTab::from($tab)->value;
        $this->conversationView = null;
        unset($this->activeTab);
    }

    public function setView(string $view): void
    {
        if (! $this->showsConversations()) {
            return;
        }

        $this->conversationView = ConversationView::from($view);
        $this->tab = null;
        unset($this->activeTab);
        $this->conversationsCursor = null;
        $this->sendersCursor = null;
        $this->expandedSender = null;
        unset($this->activeView, $this->conversations, $this->showsUnlinkedSenders, $this->unlinkedSenders, $this->expandedSenderRows);
    }

    // The page re-reads its rows each round trip, so a refetch drops the counts and the cached page.
    public function refreshConversations(): void
    {
        if (! $this->showsConversations()) {
            return;
        }

        $this->conversationView ??= $this->activeView;
        Cache::forget(ConversationsQuery::countsCacheKey($this->authUser()));
        unset($this->viewCounts, $this->conversations, $this->selectedEmail, $this->unlinkedSenders, $this->expandedSenderRows);
    }

    /**
     * @param  array<mixed>  $seenThrough  conversation key to the last inbound time its row showed, or null
     * @return array{applied: list<string>, skipped: list<array{reason: string, label: string, count: int}>}
     */
    #[Renderless]
    public function markDone(array $seenThrough): array
    {
        return $this->triage(MarkConversationsDone::class, $seenThrough);
    }

    /**
     * @param  array<mixed>  $keys
     * @return array{applied: list<string>, skipped: list<array{reason: string, label: string, count: int}>}
     */
    #[Renderless]
    public function reopen(array $keys): array
    {
        return $this->triage(ReopenConversations::class, $keys);
    }

    /**
     * @param  array<mixed>  $keys
     * @return array{applied: list<string>, skipped: list<array{reason: string, label: string, count: int}>}
     */
    #[Renderless]
    public function markRead(array $keys): array
    {
        return $this->triage(MarkConversationsRead::class, $keys);
    }

    /**
     * @param  array<mixed>  $keys
     * @return array{applied: list<string>, skipped: list<array{reason: string, label: string, count: int}>}
     */
    #[Renderless]
    public function markUnread(array $keys): array
    {
        return $this->triage(MarkConversationsUnread::class, $keys);
    }

    /**
     * @param  class-string<MarkConversationsDone|ReopenConversations|MarkConversationsRead|MarkConversationsUnread>  $action
     * @param  array<mixed>  $keys
     * @return array{applied: list<string>, skipped: list<array{reason: string, label: string, count: int}>}
     */
    private function triage(string $action, array $keys): array
    {
        if (! $this->showsConversations()) {
            return new TriageOutcome([])->toArray();
        }

        // The default view follows the counts, so emptying Needs reply would otherwise switch the list to All.
        $this->conversationView ??= $this->activeView;
        $outcome = resolve($action)->execute($this->authUser(), $keys);
        unset($this->viewCounts, $this->conversations);

        return $outcome->toArray();
    }

    public function nextConversationsPage(): void
    {
        $this->conversationView ??= $this->activeView;
        $this->moveConversationsCursor($this->conversations->nextCursor);
    }

    public function previousConversationsPage(): void
    {
        $this->conversationView ??= $this->activeView;
        $this->moveConversationsCursor($this->conversations->previousCursor);
    }

    /** @return list<ConversationRow> */
    protected function conversationRows(): array
    {
        return $this->conversations->rows;
    }

    private function moveConversationsCursor(?string $cursor): void
    {
        if ($cursor === null) {
            return;
        }

        $this->conversationsCursor = $cursor;
        unset($this->conversations);
    }

    #[Computed]
    public function activeView(): ConversationView
    {
        if ($this->conversationView instanceof ConversationView) {
            return $this->conversationView;
        }

        return $this->viewCounts['needs_reply'] > 0 ? ConversationView::NeedsReply : ConversationView::All;
    }

    /** @return array{needs_reply: int, unlinked: int, automated: int} */
    #[Computed]
    public function viewCounts(): array
    {
        return resolve(ConversationsQuery::class)->counts($this->authUser(), $this->conversationFilters);
    }

    #[Computed]
    public function showsConversations(): bool
    {
        $workspace = filament()->getTenant();

        return $this->conversationsEnabled($workspace instanceof Workspace ? $workspace : null);
    }

    #[Computed]
    public function conversations(): ConversationPage
    {
        return resolve(ConversationsQuery::class)->paginate(
            $this->authUser(),
            $this->activeView,
            $this->conversationFilters,
            cursor: $this->conversationsCursor,
        );
    }

    #[Computed]
    public function selectedEmail(): ?Email
    {
        if ($this->selectedEmailId === null || ! $this->showsConversations()) {
            return null;
        }

        return $this->conversationCopy($this->selectedEmailId);
    }

    /** @return SupportCollection<int, EmailAccessRequest> */
    #[Computed]
    public function pendingAccessRequests(): SupportCollection
    {
        $email = $this->selectedEmail;

        if (! $email instanceof Email) {
            return collect();
        }

        return $this->pendingAccessRequestsFor($email);
    }

    public function selectEmail(string $id): void
    {
        $this->openConversationReader($id);
    }

    #[On('email:open')]
    public function openRequestedEmail(string $emailId): void
    {
        $this->openLinkedEmail($emailId);
    }

    public function deselectEmail(): void
    {
        $this->selectedEmailId = null;
        unset($this->selectedEmail);

        $this->dispatch('composer:dismiss-inline');
    }

    #[Computed]
    public function hasConnectedMailbox(): bool
    {
        $workspace = filament()->getTenant();

        return ConnectedAccount::hasConnectedFor($this->authUser(), $workspace instanceof Workspace ? $workspace : null);
    }

    #[Computed]
    public function connectState(): MailboxConnectState
    {
        $workspace = filament()->getTenant();

        return MailboxConnectState::for($this->authUser(), $workspace instanceof Workspace ? $workspace : null);
    }

    #[Computed]
    public function sharingStepUrl(): ?string
    {
        $workspace = filament()->getTenant();
        $mailbox = $workspace instanceof Workspace
            ? ConnectedAccount::query()->newestConnectedFor($this->authUser(), $workspace)->first()
            : null;

        return $mailbox instanceof ConnectedAccount
            ? MailboxSharingPage::getUrl(['mailbox' => $mailbox->getKey(), 'return' => self::getUrl()])
            : null;
    }

    /** @return Collection<int, ConnectedAccount> */
    #[Computed]
    public function unhealthyMailboxes(): Collection
    {
        $workspace = filament()->getTenant();

        if (! $workspace instanceof Workspace) {
            return new Collection;
        }

        return ConnectedAccount::query()->ownedBy($this->authUser(), $workspace)->connected()->unhealthy()->with('workspace')->get();
    }

    public function connectMailboxAction(): ConnectMailboxAction
    {
        return ConnectMailboxAction::make();
    }

    public function connectAzureAction(): ConnectMailboxAction
    {
        return ConnectMailboxAction::microsoft();
    }

    /**
     * Badge counts for the tab bar. Drafts are the user's own unsent messages,
     * the outbox counts queued mail, and templates count what the user may apply.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function tabCounts(): array
    {
        $user = $this->authUser();
        $workspaceId = $user->current_workspace_id;

        $counts = DB::query()
            ->selectSub(Email::query()
                ->forWorkspace($workspaceId)
                ->where('user_id', $user->getKey())
                ->where('status', EmailStatus::DRAFT)
                ->toBase()
                ->selectRaw('count(*)'), EmailPageTab::DRAFTS->value)
            ->selectSub(OutboxTab::QUEUED->constrain(Email::query()
                ->forWorkspace($workspaceId)
                ->where('user_id', $user->getKey())
                ->where('direction', EmailDirection::OUTBOUND))
                ->toBase()
                ->selectRaw('count(*)'), EmailPageTab::OUTBOX->value)
            ->selectSub(EmailTemplate::query()
                ->where('workspace_id', $workspaceId)
                ->where(fn (Builder $q): Builder => $q
                    ->where('is_shared', true)
                    ->orWhere('created_by', $user->getKey()))
                ->toBase()
                ->selectRaw('count(*)'), EmailPageTab::TEMPLATES->value)
            ->first();

        return array_map(intval(...), (array) $counts);
    }

    private function authUser(): User
    {
        /** @var User */
        return auth()->user();
    }
}
