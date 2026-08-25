<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Relaticle\EmailIntegration\Actions\ApproveEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\DenyEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\MarkAllEmailsAsReadAction;
use Relaticle\EmailIntegration\Actions\MarkEmailAsReadAction;
use Relaticle\EmailIntegration\Actions\RequestEmailAccessAction;
use Relaticle\EmailIntegration\Actions\UpdateEmailSharingAction;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailComposeActions;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailFeatureFlag;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Models\EmailThread;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;
use Relaticle\EmailIntegration\Services\EmailThreadSummaryService;

abstract class BaseRecordEmailsPage extends Page
{
    use HasEmailComposeActions;
    use HasEmailFeatureFlag;
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
     * The last crumb. Without this it is the headlined class name — "Company Emails
     * Page" — which reads as a class, not a place.
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
            $this->composeEmailAction(),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, Email&object{pivot: MorphPivot}>
     */
    #[Computed]
    public function emails(): LengthAwarePaginator
    {
        $user = $this->authUser();

        /** @var Company|Opportunity|People $record */
        $record = $this->getRecord();

        $query = $record
            ->emails()
            // participants + shares are read per row by the privacy policy; eager-load to avoid N+1.
            ->with(['from', 'labels', 'participants', 'shares'])
            ->withReadStateFor($user->getKey())
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
        if ($this->hasActiveConnectedAccount()) {
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
    public function selectedEmail(): ?Email
    {
        if ($this->selectedEmailId === null) {
            return null;
        }

        /** @var Company|Opportunity|People $record */
        $record = $this->getRecord();

        /** @var Email|null */
        return $record
            ->emails()
            ->with(['body', 'participants', 'labels', 'attachments', 'from'])
            ->withGlobalScope('visible', new VisibleEmailScope($this->authUser()))
            ->whereKey($this->selectedEmailId)
            ->first();
    }

    #[Computed]
    public function inboxUnreadCount(): int
    {
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
        $this->selectedEmailId = $id;

        // A reply answers the message that was open; it cannot stay docked under a
        // different one. The composer saves whatever was typed as a draft.
        $this->dispatch('composer:dismiss-inline');

        // ...and if this message already has an unfinished reply, bring it back up.
        $this->dispatch('composer:resume-draft', emailId: $id);

        // Optimistically mark the email as read so the unread count updates immediately
        resolve(MarkEmailAsReadAction::class)->execute($id, $this->authUser());

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
     * Access requests waiting on the reader — only ever their own mail, since only
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

    protected function manageSharingAction(): Action
    {
        return Action::make('manageSharing')
            ->label(__('filament/pages/record-emails.actions.manage_sharing.label'))
            ->icon('ri-share-line')
            ->color('gray')
            ->iconButton()
            ->extraAttributes(['class' => 'fi-email-reader-action'])
            ->tooltip(__('filament/pages/record-emails.actions.manage_sharing.label'))
            ->modalHeading(__('filament/pages/record-emails.actions.manage_sharing.modal_heading'))
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel(__('filament/pages/record-emails.actions.manage_sharing.submit'))
            ->schema([
                // Two halves of one decision, side by side: what the workspace at large
                // gets, and who is named individually. Stacked, the second half reads as
                // an afterthought below the fold.
                Grid::make(['default' => 1, 'md' => 12])
                    ->schema([
                        // Cards rather than a select: each tier is a decision about who
                        // sees what, which a dropdown of four nouns hides. Same cards as
                        // the account settings page.
                        Section::make(__('filament/pages/record-emails.fields.privacy_tier.label'))
                            ->icon('heroicon-o-globe-alt')
                            ->compact()
                            ->columnSpan(['default' => 1, 'md' => 5])
                            ->schema([
                                Radio::make('privacy_tier')
                                    ->hiddenLabel()
                                    ->view('email-integration::forms.sharing-tier-cards')
                                    ->viewData(['ariaLabel' => __('filament/pages/record-emails.fields.privacy_tier.label')])
                                    ->required(),
                            ]),

                        Section::make(__('filament/pages/record-emails.fields.shares.label'))
                            ->description(__('filament/pages/email-inbox.sharing.fields.shares.description'))
                            ->icon('heroicon-o-user-group')
                            ->compact()
                            ->columnSpan(['default' => 1, 'md' => 7])
                            ->schema([
                                Repeater::make('shares')
                                    ->hiddenLabel()
                                    ->defaultItems(0)
                                    // Who was added first says nothing about access, so
                                    // the drag handle is just noise in the row header.
                                    ->reorderable(false)
                                    ->addActionLabel(__('filament/pages/email-inbox.sharing.fields.shares.add_action_label'))
                                    // Not ->table(): that layout quietly stacks once its
                                    // container is this narrow, which is what put the
                                    // delete button on a line of its own. Naming the row
                                    // instead gives each entry a header that carries the
                                    // delete inline, and the two fields sit side by side.
                                    ->itemLabel(fn (array $state): string => $this->teammateOptions()[$state['shared_with'] ?? null]
                                        ?? __('filament/pages/email-inbox.sharing.fields.shares.new_item'))
                                    ->columns(2)
                                    ->schema([
                                        Select::make('shared_with')
                                            ->label(__('filament/pages/record-emails.fields.shared_with.label'))
                                            ->hiddenLabel()
                                            ->placeholder(__('filament/pages/email-inbox.sharing.fields.shared_with.placeholder'))
                                            ->options(fn (): array => $this->teammateOptions())
                                            ->searchable()
                                            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                            ->required()
                                            ->distinct(),

                                        Select::make('tier')
                                            ->label(__('filament/pages/record-emails.fields.tier.label'))
                                            ->hiddenLabel()
                                            ->options(EmailPrivacyTier::class)
                                            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                            ->required(),
                                    ]),
                            ]),
                    ]),
            ])
            ->fillForm(function (array $arguments): array {
                $email = $this->resolveTeamEmail($arguments['emailId'] ?? null, 'share');

                if (! $email instanceof Email) {
                    return [];
                }

                return [
                    'privacy_tier' => $email->privacy_tier->value,
                    'shares' => $email->shares()
                        ->get()
                        ->map(fn (EmailShare $share): array => [
                            'shared_with' => $share->shared_with,
                            'tier' => $share->tier,
                        ])
                        ->all(),
                ];
            })
            ->action(function (array $data, array $arguments): void {
                $email = $this->resolveTeamEmail($arguments['emailId'] ?? null, 'share');

                abort_if(! $email instanceof Email, 403);

                resolve(UpdateEmailSharingAction::class)->execute(
                    $email,
                    $this->authUser(),
                    $data['privacy_tier'] instanceof EmailPrivacyTier
                        ? $data['privacy_tier']
                        : EmailPrivacyTier::from($data['privacy_tier']),
                    $data['shares'] ?? [],
                );

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/record-emails.notifications.sharing_saved.title'))
                    ->send();
            });
    }

    /**
     * Teammates who can be given access to an email: everyone else on the team.
     *
     * @return array<string, string>
     */
    private function teammateOptions(): array
    {
        $user = $this->authUser();

        return User::query()
            ->where('current_team_id', $user->current_team_id)
            ->where('id', '!=', $user->getKey())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    protected function summarizeThreadAction(): Action
    {
        return Action::make('summarizeThread')
            ->label(__('filament/pages/record-emails.actions.summarize_thread.label'))
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->iconButton()
            ->extraAttributes(['class' => 'fi-email-reader-action'])
            ->tooltip(__('filament/pages/record-emails.actions.summarize_thread.label'))
            ->modalHeading(__('filament/pages/record-emails.actions.summarize_thread.modal_heading'))
            ->modalIcon('heroicon-o-sparkles')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(function (array $arguments): View {
                $email = $this->resolveTeamEmail($arguments['emailId'] ?? null, 'viewBody');

                if (! $email instanceof Email) {
                    return view('filament.actions.ai-summary', ['summary' => null]);
                }

                return $this->buildThreadSummaryView($email);
            });
    }

    protected function requestAccessAction(): Action
    {
        return Action::make('requestAccess')
            ->label(__('filament/pages/record-emails.actions.request_access.label'))
            ->icon('heroicon-o-key')
            ->color('gray')
            ->iconButton()
            ->extraAttributes(['class' => 'fi-email-reader-action'])
            ->tooltip(__('filament/pages/record-emails.actions.request_access.label'))
            ->schema([
                Select::make('tier_requested')
                    ->label(__('filament/pages/record-emails.fields.tier_requested.label'))
                    ->options([
                        EmailPrivacyTier::SUBJECT->value => EmailPrivacyTier::SUBJECT->getLabel(),
                        EmailPrivacyTier::FULL->value => EmailPrivacyTier::FULL->getLabel(),
                    ])
                    ->required(),
            ])
            ->action(function (array $data, array $arguments): void {
                $email = $this->resolveTeamEmail($arguments['emailId'] ?? null, 'requestAccess');

                abort_if(! $email instanceof Email, 403);

                $request = resolve(RequestEmailAccessAction::class)->execute(
                    $email,
                    $this->authUser(),
                    $data['tier_requested'] instanceof EmailPrivacyTier
                        ? $data['tier_requested']
                        : EmailPrivacyTier::from($data['tier_requested']),
                );

                if (! $request instanceof EmailAccessRequest) {
                    Notification::make()
                        ->warning()
                        ->title(__('filament/pages/record-emails.notifications.pending_request.title'))
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/record-emails.notifications.access_request_sent.title'))
                    ->send();
            });
    }

    protected function approveAccessRequestAction(): Action
    {
        return Action::make('approveAccessRequest')
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-check-circle')
            ->modalIconColor('success')
            ->modalHeading(__('filament/pages/record-emails.actions.approve_access_request.modal_heading'))
            ->modalDescription(fn (array $arguments): string => sprintf(
                'Grant %s access to this email?',
                $this->requesterNameForOwnedRequest($arguments['requestId'] ?? null),
            ))
            ->modalSubmitActionLabel('Approve')
            ->color('success')
            ->action(function (array $arguments): void {
                $accessRequest = EmailAccessRequest::query()
                    ->with(['email', 'owner', 'requester'])
                    ->whereKey($arguments['requestId'] ?? null)
                    ->where('owner_id', $this->authUser()->getKey())
                    ->first();

                if ($accessRequest === null) {
                    return;
                }

                resolve(ApproveEmailAccessRequestAction::class)->execute($accessRequest, $this->authUser());

                unset($this->selectedEmail);

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/record-emails.notifications.access_request_approved.title'))
                    ->send();
            });
    }

    protected function denyAccessRequestAction(): Action
    {
        return Action::make('denyAccessRequest')
            ->requiresConfirmation()
            ->modalHeading(__('filament/pages/record-emails.actions.deny_access_request.modal_heading'))
            ->modalDescription(fn (array $arguments): string => sprintf(
                'Deny %s\'s request for access to this email?',
                $this->requesterNameForOwnedRequest($arguments['requestId'] ?? null),
            ))
            ->modalSubmitActionLabel('Deny')
            ->color('danger')
            ->action(function (array $arguments): void {
                $accessRequest = EmailAccessRequest::query()
                    ->with(['requester'])
                    ->whereKey($arguments['requestId'] ?? null)
                    ->where('owner_id', $this->authUser()->getKey())
                    ->first();

                if ($accessRequest === null) {
                    return;
                }

                resolve(DenyEmailAccessRequestAction::class)->execute($accessRequest, $this->authUser());

                unset($this->selectedEmail);

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/record-emails.notifications.access_request_denied.title'))
                    ->send();
            });
    }

    private function buildThreadSummaryView(Email $email): View
    {
        $thread = EmailThread::query()
            ->where('thread_id', $email->thread_id)
            ->where('connected_account_id', $email->connected_account_id)
            ->first();

        if ($thread === null) {
            return view('filament.actions.ai-summary', ['summary' => null]);
        }

        $summary = resolve(EmailThreadSummaryService::class)
            ->getSummary($thread, $this->authUser());

        return view('filament.actions.ai-summary', ['summary' => $summary]);
    }

    /**
     * Resolve an email by client-supplied id, scoped to the active team and gated by policy.
     * Returns null when the email is outside the viewer's team or the ability is denied.
     */
    private function resolveTeamEmail(?string $emailId, string $ability): ?Email
    {
        if ($emailId === null) {
            return null;
        }

        $user = $this->authUser();

        $email = Email::query()
            ->forTeam($user->current_team_id)
            ->whereKey($emailId)
            ->first();

        if ($email === null) {
            return null;
        }

        if (! $user->can($ability, $email)) {
            return null;
        }

        return $email;
    }

    private function requesterNameForOwnedRequest(?string $requestId): string
    {
        if ($requestId === null) {
            return 'this user';
        }

        return EmailAccessRequest::query()
            ->whereKey($requestId)
            ->where('owner_id', $this->authUser()->getKey())
            ->first()?->requester->name ?? 'this user';
    }

    private function authUser(): User
    {
        /** @var User */
        return auth()->user();
    }
}
