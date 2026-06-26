<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Relaticle\EmailIntegration\Actions\ApproveEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\DenyEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\MarkAllEmailsAsReadAction;
use Relaticle\EmailIntegration\Actions\MarkEmailAsReadAction;
use Relaticle\EmailIntegration\Actions\RequestEmailAccessAction;
use Relaticle\EmailIntegration\Actions\UpdateEmailSharingAction;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailComposeActions;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailFeatureFlag;
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

    public EmailFolder $folder = EmailFolder::Inbox;

    public ?string $selectedEmailId = null;

    public string $search = '';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $firstItem = $this->emails()->items()[0] ?? null;
        $this->selectedEmailId = $firstItem instanceof Email ? $firstItem->id : null;
    }

    protected function getCrmRecord(): Model
    {
        return $this->getRecord();
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

    public function replyAction(): Action
    {
        return $this->replyForwardModeAction('reply', 'reply', $this->selectedEmailId);
    }

    public function replyAllAction(): Action
    {
        return $this->replyForwardModeAction('replyAll', 'reply_all', $this->selectedEmailId);
    }

    public function forwardAction(): Action
    {
        return $this->replyForwardModeAction('forward', 'forward', $this->selectedEmailId);
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

        // Optimistically mark the email as read so the unread count updates immediately
        resolve(MarkEmailAsReadAction::class)->execute($id, $this->authUser());

        unset($this->inboxUnreadCount);
    }

    public function setFolder(string $folder): void
    {
        $this->folder = EmailFolder::from($folder);
        $this->search = '';
        $this->resetPage();
        unset($this->emails);
        $firstItem = $this->emails()->items()[0] ?? null;
        $this->selectedEmailId = $firstItem instanceof Email ? $firstItem->id : null;
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
            ->icon('heroicon-o-lock-open')
            ->color('gray')
            ->link()
            ->extraAttributes(['class' => 'text-xs'])
            ->modalHeading(__('filament/pages/record-emails.actions.manage_sharing.modal_heading'))
            ->modalSubmitActionLabel(__('filament/pages/record-emails.actions.manage_sharing.submit'))
            ->schema([
                Select::make('privacy_tier')
                    ->label(__('filament/pages/record-emails.fields.privacy_tier.label'))
                    ->options(EmailPrivacyTier::class)
                    ->required(),

                Repeater::make('shares')
                    ->label(__('filament/pages/record-emails.fields.shares.label'))
                    ->defaultItems(0)
                    ->addActionLabel('Add teammate')
                    ->columns(2)
                    ->schema([
                        Select::make('shared_with')
                            ->label(__('filament/pages/record-emails.fields.shared_with.label'))
                            ->options(function (): array {
                                $user = $this->authUser();

                                return User::query()
                                    ->where('current_team_id', $user->current_team_id)
                                    ->where('id', '!=', $user->getKey())
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                            ->required()
                            ->distinct(),

                        Select::make('tier')
                            ->label(__('filament/pages/record-emails.fields.tier.label'))
                            ->options(EmailPrivacyTier::class)
                            ->required(),
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

    protected function summarizeThreadAction(): Action
    {
        return Action::make('summarizeThread')
            ->label(__('filament/pages/record-emails.actions.summarize_thread.label'))
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->link()
            ->extraAttributes(['class' => 'text-xs'])
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
            ->link()
            ->extraAttributes(['class' => 'text-xs'])
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
