<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\RelationManagers;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Filament\Actions\ConnectMailboxAction;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailComposeActions;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailReaderActions;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;
use Relaticle\EmailIntegration\Services\EmailSearchService;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;
use Relaticle\EmailIntegration\Services\PreferredEmailCopyService;

/**
 * @property-read LengthAwarePaginator<int, Email&object{pivot: MorphPivot}> $emails
 * @property-read Email|null $selectedEmail
 * @property-read Collection<int, EmailAccessRequest> $pendingAccessRequests
 */
abstract class BaseEmailsRelationManager extends RelationManager
{
    use HasEmailComposeActions;
    use HasEmailReaderActions;

    protected static string $relationship = 'emails';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-envelope';

    protected static ?string $badgeColor = 'gray';

    protected string $view = 'email-integration::filament.relation-managers.emails-relation-manager';

    public ?string $selectedEmailId = null;

    public string $search = '';

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

    protected function getCrmRecord(): Model
    {
        return $this->getOwnerRecord();
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
        }

        unset($this->emails);
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
                'accessRequests as viewer_has_pending_access_request' => fn (Builder $query) => $query
                    ->where('requester_id', $user->getKey())
                    ->where('status', EmailAccessRequestStatus::PENDING),
            ])
            ->withGlobalScope('visible', new VisibleEmailScope($user));

        if (filled($this->search)) {
            resolve(EmailSearchService::class)->applyToQuery($query, $user, $this->search);
        }

        resolve(PreferredEmailCopyService::class)->restrictToPreferredCopies($query->getQuery(), $user);

        $paginator = $query->latest('sent_at')->paginate(20, pageName: $this->getTablePaginationPageName());

        resolve(PreferredEmailCopyService::class)->hydrateMailboxAccess($paginator->getCollection(), $user, $this->ownerRecordWithEmails());

        return $paginator;
    }

    public function connectMailboxAction(): ConnectMailboxAction
    {
        return ConnectMailboxAction::make();
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

        /** @var Email|null $email */
        $email = $this->ownerRecordWithEmails()
            ->emails()
            ->with(['body', 'participants', 'labels', 'attachments', 'from'])
            ->withGlobalScope('visible', new VisibleEmailScope($this->authUser()))
            ->whereKey($this->selectedEmailId)
            ->first();

        if (! $email instanceof Email || $this->authUser()->cannot('viewBody', $email)) {
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
        unset($this->emails);
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
