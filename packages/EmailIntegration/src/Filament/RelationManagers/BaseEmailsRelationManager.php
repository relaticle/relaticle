<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\RelationManagers;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource as FilamentResource;
use Filament\Tables\Table;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Relaticle\EmailIntegration\Data\ConversationFilters;
use Relaticle\EmailIntegration\Data\ConversationPage;
use Relaticle\EmailIntegration\Data\ConversationRow;
use Relaticle\EmailIntegration\Enums\ConversationView;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\Actions\ConnectMailboxAction;
use Relaticle\EmailIntegration\Filament\Concerns\HasConversationReader;
use Relaticle\EmailIntegration\Filament\Concerns\HasConversationsFlag;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailComposeActions;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailReaderActions;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;
use Relaticle\EmailIntegration\Services\EmailSearchService;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;
use Relaticle\EmailIntegration\Services\PreferredEmailCopyService;

/**
 * @property-read LengthAwarePaginator<int, Email&object{pivot: MorphPivot}> $emails
 * @property-read ConversationPage $conversations
 * @property-read int $automatedCount
 * @property-read bool $showsConversations
 * @property-read Email|null $selectedEmail
 * @property-read Collection<int, EmailAccessRequest> $pendingAccessRequests
 */
abstract class BaseEmailsRelationManager extends RelationManager
{
    use HasConversationReader;
    use HasConversationsFlag;
    use HasEmailComposeActions;
    use HasEmailReaderActions;

    protected static string $relationship = 'emails';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-envelope';

    protected static ?string $badgeColor = 'gray';

    protected static bool $isBadgeDeferred = true;

    protected string $view = 'email-integration::filament.relation-managers.emails-relation-manager';

    public ?string $selectedEmailId = null;

    public string $search = '';

    public bool $showAutomated = false;

    public ?string $conversationsCursor = null;

    /**
     * @param  class-string<FilamentResource>  $resource
     */
    public static function relationKeyOn(string $resource): ?string
    {
        foreach ($resource::getRelations() as $key => $manager) {
            if (is_string($manager) && is_a($manager, self::class, true)) {
                return (string) $key;
            }
        }

        return null;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        if (! $ownerRecord instanceof Company && ! $ownerRecord instanceof Opportunity && ! $ownerRecord instanceof People) {
            return null;
        }

        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        return resolve(EmailVisibilityService::class)->visibleEmailCountBadge($ownerRecord, $user);
    }

    public function mount(): void
    {
        parent::mount();

        if ($this->selectedEmailId !== null && $this->showsConversations()) {
            $this->openLinkedEmail($this->selectedEmailId);
        }
    }

    // The flag-off tab keeps today's URL, where the panel's own reader opens `?email=`.
    /**
     * @return array<string, array{as: string}>
     */
    protected function queryString(): array
    {
        return $this->showsConversations() ? ['selectedEmailId' => ['as' => 'email']] : [];
    }

    public function table(Table $table): Table
    {
        // The tab lists emails() itself. The default relationship table skips the
        // visibility scopes and Livewire lets a client call getTableRecords().
        return $table->modifyQueryUsing(fn (Builder $query): Builder => $query->whereRaw('0 = 1'));
    }

    #[On('composer:sent')]
    public function showQueuedSendOnRecord(?string $emailId = null): void
    {
        $this->search = '';

        if (filled($emailId)) {
            $this->selectedEmailId = $emailId;
            unset($this->selectedEmail);
            $this->selectedEmailId = $this->selectedEmail() instanceof Email ? $emailId : null;
        }

        $this->conversationsCursor = null;
        $this->expandedEmailIds = [];
        unset($this->emails, $this->conversations, $this->automatedCount, $this->selectedEmail, $this->thread, $this->expandedBodies, $this->selectedConversationRow);
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
        if ($this->hidesRecordMailbox()) {
            return new ConversationPage([], null, null);
        }

        return resolve(ConversationsQuery::class)->paginate(
            $this->authUser(),
            $this->showAutomated ? ConversationView::Automated : ConversationView::All,
            ConversationFilters::forRecord($this->ownerRecordWithEmails(), $this->search),
            20,
            $this->conversationsCursor,
        );
    }

    #[Computed]
    public function automatedCount(): int
    {
        if ($this->hidesRecordMailbox()) {
            return 0;
        }

        return resolve(ConversationsQuery::class)
            ->count($this->authUser(), ConversationView::Automated, ConversationFilters::forRecord($this->ownerRecordWithEmails()));
    }

    public function updatedShowAutomated(): void
    {
        $this->conversationsCursor = null;
        unset($this->conversations);
    }

    public function nextConversationsPage(): void
    {
        $this->moveConversationsCursor($this->conversations->nextCursor);
    }

    public function previousConversationsPage(): void
    {
        $this->moveConversationsCursor($this->conversations->previousCursor);
    }

    /**
     * @return LengthAwarePaginator<int, Email&object{pivot: MorphPivot}>
     */
    #[Computed]
    public function emails(): LengthAwarePaginator
    {
        if ($this->hidesRecordMailbox()) {
            return new LengthAwarePaginator([], 0, 20);
        }

        $user = $this->authUser();

        $query = $this->ownerRecordWithEmails()
            ->emails()
            // participants + shares are read per row by the privacy policy; eager-load to avoid N+1.
            ->with(['from', 'labels', 'participants', 'shares', 'user', 'connectedAccount.user'])
            ->withReadStateFor($user->getKey())
            ->withExists([
                'accessRequests as viewer_has_pending_access_request' => fn (EloquentBuilder $requests): EloquentBuilder => $requests
                    ->where('requester_id', $user->getKey())
                    ->scopes(['open']),
            ]);

        if (filled($this->search)) {
            resolve(EmailSearchService::class)->applyToQuery($query, $user, $this->search);
        }

        resolve(PreferredEmailCopyService::class)->restrictToVisiblePreferredCopies($query->getQuery(), $user);

        $paginator = $query->latest('sent_at')->paginate(20, pageName: $this->getTablePaginationPageName());

        resolve(PreferredEmailCopyService::class)->hydrateMailboxAccess($paginator->getCollection(), $user, $this->ownerRecordWithEmails());

        return $paginator;
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
     * Take the whole tab over with the connect prompt only when the user has nothing
     * to read here: teammates without a mailbox of their own still get the thread list
     * for emails shared with them.
     */
    #[Computed]
    public function showConnectPrompt(): bool
    {
        if ($this->hidesRecordMailbox() || $this->hasActiveConnectedAccount()) {
            return false;
        }

        return $this->ownerRecordWithEmails()
            ->emails()
            ->withGlobalScope('visible', new VisibleEmailScope($this->authUser()))
            ->doesntExist();
    }

    /** @return EloquentCollection<int, ConnectedAccount> */
    #[Computed]
    public function unhealthyMailboxes(): EloquentCollection
    {
        $workspace = filament()->getTenant();

        if (! $workspace instanceof Workspace) {
            return new EloquentCollection;
        }

        return ConnectedAccount::query()->ownedBy($this->authUser(), $workspace)->connected()->unhealthy()->with('workspace')->get();
    }

    #[Computed]
    public function hidesRecordMailbox(): bool
    {
        return resolve(EmailVisibilityService::class)->hidesRecordMailbox($this->getOwnerRecord());
    }

    /**
     * @return array{heading: string, description: string}|null
     */
    #[Computed]
    public function recordMailboxHiddenCopy(): ?array
    {
        $record = $this->getOwnerRecord();

        if (! $record instanceof People && ! $record instanceof Company) {
            return null;
        }

        return resolve(EmailVisibilityService::class)->recordMailboxHiddenCopy($record);
    }

    #[Computed]
    public function selectedEmail(): ?Email
    {
        if ($this->selectedEmailId === null || $this->hidesRecordMailbox()) {
            return null;
        }

        if ($this->showsConversations()) {
            return $this->recordConversationCopy($this->selectedEmailId) ?? $this->recordEmail($this->selectedEmailId, undeliveredOwnOnly: true);
        }

        return $this->recordEmail($this->selectedEmailId);
    }

    private function recordConversationCopy(string $emailId): ?Email
    {
        $email = $this->conversationCopy($emailId);

        if (! $email instanceof Email || ! resolve(ConversationsQuery::class)->contains($this->authUser(), (string) $email->conversation_key, ConversationFilters::forRecord($this->ownerRecordWithEmails()))) {
            return null;
        }

        return $email;
    }

    // A send just queued from this tab is not delivered yet, so no conversation holds it.
    private function recordEmail(string $emailId, bool $undeliveredOwnOnly = false): ?Email
    {
        $user = $this->authUser();

        /** @var Email|null $email */
        $email = $this->ownerRecordWithEmails()
            ->emails()
            ->with(['body', 'participants', 'labels', 'attachments', 'from'])
            ->withGlobalScope('visible', new VisibleEmailScope($user))
            ->when($undeliveredOwnOnly, fn (EloquentBuilder $own): EloquentBuilder => $own
                ->where('emails.user_id', $user->getKey())
                ->whereNotIn('emails.status', [EmailStatus::SYNCED, EmailStatus::SENT]))
            ->whereKey($emailId)
            ->first();

        if (! $email instanceof Email || $user->cannot('viewBody', $email)) {
            return null;
        }

        return $email;
    }

    /**
     * @return Collection<int, EmailAccessRequest>
     */
    #[Computed]
    public function pendingAccessRequests(): Collection
    {
        $email = $this->selectedEmail();

        if (! $email instanceof Email) {
            return collect();
        }

        return $this->pendingAccessRequestsFor($email);
    }

    public function selectEmail(string $id): void
    {
        if ($this->showsConversations()) {
            $this->openConversationReader($id);

            return;
        }

        $this->openEmailReader($id);
    }

    public function deselectEmail(): void
    {
        $this->selectedEmailId = null;
        unset($this->selectedEmail);

        // Dismissing the dock persists whatever was typed as a draft, so closing the
        // reader can never silently drop a half-written reply.
        $this->dispatch('composer:dismiss-inline');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->conversationsCursor = null;
        unset($this->emails, $this->conversations);
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

    private function ownerRecordWithEmails(): Company|Opportunity|People
    {
        /** @var Company|Opportunity|People */
        return $this->getOwnerRecord();
    }

    private function authUser(): User
    {
        /** @var User */
        return auth()->user();
    }
}
