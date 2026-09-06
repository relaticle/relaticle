<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Relaticle\EmailIntegration\Actions\MarkAllEmailsAsReadAction;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailComposeActions;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailFeatureFlag;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailReaderActions;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;

abstract class BaseRecordEmailsPage extends Page
{
    use HasEmailComposeActions;
    use HasEmailFeatureFlag;
    use HasEmailReaderActions;
    use InteractsWithRecord;
    use WithPagination;

    protected string $view = 'filament.pages.record-emails';

    public EmailFolder $folder = EmailFolder::All;

    public ?string $selectedEmailId = null;

    public string $search = '';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    protected function getCrmRecord(): Model
    {
        return $this->getRecord();
    }

    /**
     * The last crumb. Without this it is the headlined class name, "Company Emails
     * Page", which reads as a class, not a place.
     */
    public function getBreadcrumb(): string
    {
        return __('filament/pages/record-emails.breadcrumb');
    }

    public function getTitle(): string
    {
        return __('filament/pages/record-emails.title');
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return ['reply-email' => 'openReplyModal'];
    }

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->composeEmailAction()
                ->visible(fn (): bool => $this->hasActiveConnectedAccount() && ! $this->hidesRecordMailbox()),
        ];
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

        /** @var Company|Opportunity|People $record */
        $record = $this->getRecord();

        $query = $record
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

        if ($this->folder === EmailFolder::Sent) {
            $query->sent();
        } elseif ($this->folder === EmailFolder::Inbox) {
            $query->inbox();
        }

        if (filled($this->search)) {
            $query->where(function (Builder $q): void {
                $q->where('subject', 'ilike', '%'.$this->search.'%')
                    ->orWhere('snippet', 'ilike', '%'.$this->search.'%');
            });
        }

        return $query->latest('sent_at')->paginate(20);
    }

    /**
     * Take the whole page over with the connect prompt only when the user has nothing
     * to read here: teammates without a mailbox of their own still get the thread list
     * for emails shared with them.
     */
    #[Computed]
    public function showConnectPrompt(): bool
    {
        if ($this->hidesRecordMailbox() || $this->hasActiveConnectedAccount()) {
            return false;
        }

        /** @var Company|Opportunity|People $record */
        $record = $this->getRecord();

        return $record
            ->emails()
            ->withGlobalScope('visible', new VisibleEmailScope($this->authUser()))
            ->doesntExist();
    }

    #[Computed]
    public function hidesRecordMailbox(): bool
    {
        return resolve(EmailVisibilityService::class)->hidesRecordMailbox($this->getRecord());
    }

    /**
     * @return array{heading: string, description: string}|null
     */
    #[Computed]
    public function recordMailboxHiddenCopy(): ?array
    {
        $record = $this->getRecord();

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

        /** @var Company|Opportunity|People $record */
        $record = $this->getRecord();

        /** @var Email|null $email */
        $email = $record
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

    #[Computed]
    public function inboxUnreadCount(): int
    {
        if ($this->hidesRecordMailbox()) {
            return 0;
        }

        /** @var Company|Opportunity|People $record */
        $record = $this->getRecord();

        return $record
            ->emails()
            ->withGlobalScope('visible', new VisibleEmailScope($this->authUser()))
            ->unreadFor($this->authUser()->getKey())
            ->count();
    }

    public function selectEmail(string $id): void
    {
        if (! $this->openEmailReader($id)) {
            return;
        }

        unset($this->inboxUnreadCount);
    }

    public function setFolder(string $folder): void
    {
        $this->folder = EmailFolder::from($folder);
        $this->search = '';
        $this->selectedEmailId = null;
        $this->resetPage();
        unset($this->emails);
        $this->dispatch('composer:dismiss-inline');
    }

    public function deselectEmail(): void
    {
        $this->selectedEmailId = null;
        unset($this->selectedEmail);

        // Dismissing the dock persists whatever was typed as a draft, so closing the
        // reader can never silently drop a half-written reply.
        $this->dispatch('composer:dismiss-inline');
    }

    /**
     * Access requests waiting on the reader, only ever their own mail, since only
     * the owner may grant access to it.
     *
     * @return Collection<int, EmailAccessRequest>
     */
    #[Computed]
    public function pendingAccessRequests(): Collection
    {
        $email = $this->selectedEmail();

        if (! $email instanceof Email || $email->user_id !== $this->authUser()->getKey()) {
            return collect();
        }

        return EmailAccessRequest::query()
            ->with('requester')
            ->where('email_id', $email->getKey())
            ->where('status', EmailAccessRequestStatus::PENDING)
            ->get();
    }

    /**
     * The viewer's own mailbox addresses, lowercased. Rows use these to leave the
     * reader out of their participant line: repeating your own address on every row
     * says nothing, and it is the widest thing on the row.
     *
     * @return list<string>
     */
    #[Computed]
    public function ownEmailAddresses(): array
    {
        $user = $this->authUser();

        /** @var list<string> */
        return ConnectedAccount::query()
            ->where('user_id', $user->getKey())
            ->where('team_id', $user->current_team_id)
            ->pluck('email_address')
            ->map(fn (mixed $address): string => mb_strtolower((string) $address))
            ->filter()
            ->values()
            ->all();
    }

    public function markAllAsRead(): void
    {
        /** @var Company|Opportunity|People $record */
        $record = $this->getRecord();

        $count = resolve(MarkAllEmailsAsReadAction::class)->execute($this->authUser(), $this->folder, $record);

        unset($this->inboxUnreadCount, $this->emails);

        Notification::make()
            ->success()
            ->title(trans_choice('filament/pages/email-inbox.mark_all_read.notification', $count, ['count' => $count]))
            ->send();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        unset($this->emails);
    }

    private function authUser(): User
    {
        /** @var User */
        return auth()->user();
    }
}
