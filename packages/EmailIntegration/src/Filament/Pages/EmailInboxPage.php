<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use App\Models\Team;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Relaticle\EmailIntegration\Actions\ApproveEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\DenyEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\MarkAllEmailsAsReadAction;
use Relaticle\EmailIntegration\Actions\MarkEmailAsReadAction;
use Relaticle\EmailIntegration\Actions\SendEmailAction;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Enums\EmailPageTab;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailFeatureFlag;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailReaderActions;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\EmailTemplate;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;
use Relaticle\EmailIntegration\Services\EmailTemplateRenderService;
use Relaticle\EmailIntegration\Services\PrivacyService;
use Relaticle\EmailIntegration\Support\EmailHtmlSanitizer;

final class EmailInboxPage extends Page
{
    use HasEmailFeatureFlag;
    use HasEmailReaderActions;
    use WithPagination;

    protected string $view = 'filament.pages.email-inbox';

    protected static ?string $navigationLabel = null;

    protected static ?string $title = 'Email';

    protected static ?string $slug = 'email';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static ?int $navigationSort = 6;

    public static function getNavigationLabel(): string
    {
        return __('filament/pages/email-inbox.navigation_label');
    }

    public EmailFolder $folder = EmailFolder::Inbox;

    /**
     * Which of the lists (drafts, outbox, templates) the page is showing. The tab
     * bodies are nested Livewire components shared with their standalone pages.
     */
    #[Url(as: 'tab')]
    public EmailPageTab $tab = EmailPageTab::DRAFTS;

    #[Url(as: 'email')]
    public ?string $selectedEmailId = null;

    /**
     * Active connected-account scope for the list: a ConnectedAccount id, or the
     * literal `all` for the unified cross-account view.
     */
    #[Url(as: 'account')]
    public string $accountId = '';

    public string $search = '';

    public function mount(): void
    {
        $this->folder = EmailFolder::tryFrom((string) request()->query('folder', EmailFolder::Inbox->value)) ?? EmailFolder::Inbox;
        $this->accountId = $this->resolveInitialAccountId();

    }

    /**
     * Land on the user's default account first (option 1). Honour a valid account
     * already in the URL (or the `all` sentinel); otherwise fall back to the
     * default account, then any account, then `all` when none are connected.
     */
    private function resolveInitialAccountId(): string
    {
        if ($this->accountId === 'all') {
            return 'all';
        }

        $accounts = $this->userActiveAccounts();

        if ($this->accountId !== '' && $accounts->has($this->accountId)) {
            return $this->accountId;
        }

        return (string) ($accounts->keys()->first() ?? 'all');
    }

    public function updatedAccountId(): void
    {
        if ($this->accountId !== 'all' && ! $this->userActiveAccounts()->has($this->accountId)) {
            $this->accountId = 'all';
        }

        $this->search = '';
        $this->selectedEmailId = null;
        $this->resetPage();
        unset($this->emails, $this->inboxUnreadCount);
        $this->dispatch('composer:dismiss-inline');
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return [
            'reply-email' => 'openReplyModal',
            // The composer saves/discards drafts and queues mail from outside this
            // component, so the tab badges have to be told when those counts move.
            'drafts:changed' => 'refreshTabCounts',
            'outbox:changed' => 'refreshTabCounts',
            'access-requests:changed' => 'refreshTabCounts',
        ];
    }

    public function refreshTabCounts(): void
    {
        unset($this->tabCounts);
    }

    public function openReplyModal(string $emailId, string $mode): void
    {
        $this->mountAction('replyForwardEmail', [
            'emailId' => $emailId,
            'mode' => $mode,
        ]);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->composeEmailAction(),
        ];
    }

    protected function composeEmailAction(): Action
    {
        return Action::make('composeEmail')
            ->label(__('filament/concerns/email-compose.actions.compose.label'))
            ->icon('heroicon-o-pencil-square')
            ->tooltip(__('filament/concerns/email-compose.actions.compose.tooltip'))
            ->visible(fn (): bool => $this->hasActiveConnectedAccount())
            ->action(function (): void {
                $this->dispatch('composer:open');
            });
    }

    /**
     * No page heading. The sidebar already marks Email as active; the header row
     * carries only the compose action above the tab strip.
     */
    public function getHeading(): string
    {
        return '';
    }

    /**
     * @return LengthAwarePaginator<int, Email>
     */
    #[Computed]
    public function emails(): LengthAwarePaginator
    {
        $user = $this->authUser();

        $query = Email::query()
            // participants + shares are needed by PrivacyService::effectiveTier() (which each
            // row's can('viewSubject'/'viewBody') hits), so eager-load them to avoid 2 lazy
            // queries per row, matching BaseRecordEmailsPage / BaseEmailsRelationManager.
            ->with(['from', 'labels', 'participants', 'shares'])
            ->withReadStateFor($user->getKey())
            ->forTeam($user->current_team_id)
            ->withGlobalScope('visible', new VisibleEmailScope($user));

        if ($this->accountId !== '' && $this->accountId !== 'all') {
            $query->where('connected_account_id', $this->accountId);
        }

        if ($this->folder === EmailFolder::Sent) {
            $query->sent();
        } elseif ($this->folder === EmailFolder::Inbox) {
            $query->inbox();
        }

        // `sent()`/`inbox()` already exclude drafts structurally (direction
        // OUTBOUND+sent_at NOT NULL / direction INBOUND), but the `All` folder
        // applies neither. Without this, VisibleEmailScope's owner clause
        // would surface the viewer's own in-progress drafts in the unified list.
        $query->where('status', '!=', EmailStatus::DRAFT);

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

        /** @var Email|null */
        return Email::query()
            ->with(['body', 'participants', 'labels', 'attachments', 'from'])
            ->forTeam($this->authUser()->current_team_id)
            ->withGlobalScope('visible', new VisibleEmailScope($this->authUser()))
            ->whereKey($this->selectedEmailId)
            ->first();
    }

    /**
     * Pending access requests for the open email, but only when the viewer owns
     * it. The detail pane shows an inline approve/deny strip for these.
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

    #[Computed]
    public function inboxUnreadCount(): int
    {
        $user = $this->authUser();

        $query = Email::query()
            ->forTeam($user->current_team_id)
            ->withGlobalScope('visible', new VisibleEmailScope($user))
            ->unreadFor($user->getKey());

        if ($this->accountId !== '' && $this->accountId !== 'all') {
            $query->where('connected_account_id', $this->accountId);
        }

        return $query->count();
    }

    /**
     * Covers selection changes that come from the client (the mobile back button
     * clears it) rather than from {@see selectEmail()}.
     */
    public function updatedSelectedEmailId(): void
    {
        $this->dispatch('composer:dismiss-inline');
    }

    public function selectEmail(string $id): void
    {
        $this->selectedEmailId = $id;

        // A reply answers the message that was open; it cannot stay docked under a
        // different one. The composer saves whatever was typed as a draft.
        $this->dispatch('composer:dismiss-inline');

        // ...and if this message already has an unfinished reply, bring it back up.
        $this->dispatch('composer:resume-draft', emailId: $id);

        resolve(MarkEmailAsReadAction::class)->execute($id, $this->authUser());

        unset($this->inboxUnreadCount);
    }

    public function markAllAsRead(): void
    {
        $count = resolve(MarkAllEmailsAsReadAction::class)->execute($this->authUser(), $this->folder);

        unset($this->inboxUnreadCount, $this->emails);

        Notification::make()
            ->success()
            ->title(trans_choice('filament/pages/email-inbox.mark_all_read.notification', $count, ['count' => $count]))
            ->send();
    }

    public function setTab(string $tab): void
    {
        $this->tab = EmailPageTab::from($tab);
    }

    /**
     * Badge counts for the tab bar. Drafts are the user's own unsent messages,
     * the outbox counts what is still waiting to go out, failed counts delivery
     * failures, and templates count what the user may actually apply.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function tabCounts(): array
    {
        $user = $this->authUser();
        $teamId = $user->current_team_id;

        return [
            EmailPageTab::DRAFTS->value => Email::query()
                ->forTeam($teamId)
                ->where('user_id', $user->getKey())
                ->where('status', EmailStatus::DRAFT)
                ->count(),
            EmailPageTab::OUTBOX->value => Email::query()
                ->forTeam($teamId)
                ->where('user_id', $user->getKey())
                ->where('status', EmailStatus::QUEUED)
                ->count(),
            EmailPageTab::FAILED->value => Email::query()
                ->forTeam($teamId)
                ->where('user_id', $user->getKey())
                ->where('status', EmailStatus::FAILED)
                ->count(),
            EmailPageTab::TEMPLATES->value => EmailTemplate::query()
                ->where('team_id', $teamId)
                ->where(fn (Builder $q): Builder => $q
                    ->where('is_shared', true)
                    ->orWhere('created_by', $user->getKey()))
                ->count(),
            EmailPageTab::REQUESTS->value => EmailAccessRequest::query()
                ->where('owner_id', $user->getKey())
                ->whereHas('email', fn (Builder $query): Builder => $query->where('team_id', $teamId))
                ->where('status', EmailAccessRequestStatus::PENDING)
                ->count(),
        ];
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

    public function updatedSearch(): void
    {
        $this->resetPage();
        unset($this->emails);
    }

    /**
     * Legacy single action mounted by name from the email-thread reply buttons
     * (the `reply-email` browser event → {@see openReplyModal()}), where the mode
     * and target email arrive as runtime arguments.
     */
    protected function replyForwardEmailAction(): Action
    {
        return Action::make('replyForwardEmail')
            ->link()
            ->hiddenLabel()
            ->extraAttributes(['class' => 'p-2'])
            ->icon(fn (array $arguments): string => $this->replyForwardIcon($arguments['mode'] ?? 'reply'))
            ->tooltip(fn (array $arguments): string => $this->replyForwardLabel($arguments['mode'] ?? 'reply'))
            ->modalHeading(fn (array $arguments): string => $this->replyForwardLabel($arguments['mode'] ?? 'reply'))
            ->slideOver()
            ->modalWidth(Width::SevenExtraLarge)
            ->fillForm(fn (array $arguments): array => $this->replyForwardFormData(
                $arguments['emailId'] ?? null,
                $arguments['mode'] ?? 'reply',
            ))
            ->schema($this->replyFormSchema())
            ->action(function (array $data, array $arguments): void {
                $this->submitReplyForward($data, $arguments['mode'] ?? 'reply');
            });
    }

    private function replyForwardIcon(string $mode): string
    {
        return match ($mode) {
            'reply_all' => 'ri-reply-all-line',
            'forward' => 'ri-share-forward-line',
            default => 'ri-reply-line',
        };
    }

    private function replyForwardLabel(string $mode): string
    {
        return match ($mode) {
            'reply_all' => __('filament/pages/email-inbox.reply_forward.modal_headings.reply_all'),
            'forward' => __('filament/pages/email-inbox.reply_forward.modal_headings.forward'),
            default => __('filament/pages/email-inbox.reply_forward.modal_headings.reply'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function replyForwardFormData(?string $emailId, string $mode): array
    {
        $email = $this->resolveTeamEmail($emailId, 'view');

        if (! $email instanceof Email) {
            return [];
        }

        $email->loadMissing(['participants', 'body']);

        $user = $this->authUser();

        $account = ConnectedAccount::query()
            ->where('user_id', $user->getKey())
            ->where('team_id', filament()->getTenant()?->getKey())
            ->where('status', 'active')
            ->first();

        $toParticipants = match ($mode) {
            'forward' => [],
            // Reply-all addresses the original sender PLUS the to/cc recipients
            // (never bcc), minus the user's own account address. Excluding the
            // 'from' role here would drop the very person being replied to.
            'reply_all' => $email->replyAllRecipients($account?->email_address),
            default => $email->participants
                ->where('role', 'from')
                ->pluck('email_address')
                ->all(),
        };

        // Only quote the original body when the viewer is entitled to read it.
        $quotedBody = $user->can('viewBody', $email) ? $email->body?->body_html : null;

        $subjectPrefix = $mode === 'forward' ? 'Fwd: ' : 'Re: ';

        return [
            'connected_account_id' => $account?->getKey(),
            'to' => $toParticipants,
            'subject' => $subjectPrefix.($email->subject ?? ''),
            'body_html' => '',
            'quoted_body_html' => $quotedBody,
            'mode' => $mode,
            'in_reply_to_email_id' => $mode !== 'forward' ? $email->getKey() : null,
            'privacy_tier' => $this->defaultPrivacyTier()->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function submitReplyForward(array $data, string $mode): void
    {
        if (filled($data['quoted_body_html'] ?? '')) {
            $quotedSection = $mode === 'forward'
                ? '<br><p><strong>---------- Forwarded message ----------</strong></p>'.$data['quoted_body_html']
                : '<br><blockquote style="border-left:3px solid #ccc;margin-left:0;padding-left:1rem">'.$data['quoted_body_html'].'</blockquote>';

            $data['body_html'] = ($data['body_html'] ?? '').$quotedSection;
        }

        $source = match ($mode) {
            'reply_all' => EmailCreationSource::REPLY_ALL,
            'forward' => EmailCreationSource::FORWARD,
            default => EmailCreationSource::REPLY,
        };

        resolve(SendEmailAction::class)->execute(
            data: $this->buildSendData($data, $source),
        );

        Notification::make()->title(__('filament/pages/email-inbox.reply_forward.notifications.queued.title'))->success()->send();
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

    protected function approveAccessRequestAction(): Action
    {
        return Action::make('approveAccessRequest')
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-check-circle')
            ->modalIconColor('success')
            ->modalHeading(__('filament/pages/email-inbox.approve_access_request.modal_heading'))
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
                    ->title(__('filament/pages/email-inbox.approve_access_request.notifications.approved.title'))
                    ->send();
            });
    }

    protected function denyAccessRequestAction(): Action
    {
        return Action::make('denyAccessRequest')
            ->requiresConfirmation()
            ->modalHeading(__('filament/pages/email-inbox.deny_access_request.modal_heading'))
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
                    ->title(__('filament/pages/email-inbox.deny_access_request.notifications.denied.title'))
                    ->send();
            });
    }

    /**
     * @return array<int, mixed>
     */
    private function replyFormSchema(): array
    {
        return [
            Select::make('connected_account_id')
                ->label(__('filament/pages/email-inbox.reply_form.from.label'))
                ->options(fn (): array => $this->activeAccountOptions())
                ->required(),

            TagsInput::make('to')
                ->label(__('filament/pages/email-inbox.reply_form.to.label'))
                ->placeholder(__('filament/pages/email-inbox.reply_form.to.placeholder'))
                ->required()
                ->splitKeys(['Tab', ',', ' '])
                ->suggestions(fn (): array => $this->contactEmailSuggestions()),

            Grid::make(2)
                ->schema([
                    TagsInput::make('cc')
                        ->label(__('filament/pages/email-inbox.reply_form.cc.label'))
                        ->placeholder(__('filament/pages/email-inbox.reply_form.cc.placeholder'))
                        ->splitKeys(['Tab', ',', ' '])
                        ->suggestions(fn (): array => $this->contactEmailSuggestions()),

                    TagsInput::make('bcc')
                        ->label(__('filament/pages/email-inbox.reply_form.bcc.label'))
                        ->placeholder(__('filament/pages/email-inbox.reply_form.bcc.placeholder'))
                        ->splitKeys(['Tab', ',', ' '])
                        ->suggestions(fn (): array => $this->contactEmailSuggestions()),
                ]),

            TextInput::make('subject')
                ->required()
                ->maxLength(255),

            RichEditor::make('body_html')
                ->label(__('filament/pages/email-inbox.reply_form.message.label'))
                ->required()
                ->mergeTags(EmailTemplateRenderService::MERGE_TAGS)
                ->toolbarButtons([
                    'bold', 'italic', 'underline', 'strike',
                    'link', 'bulletList', 'orderedList',
                    'blockquote', 'h2', 'h3', 'undo', 'redo',
                ]),

            Hidden::make('quoted_body_html'),
            Hidden::make('mode'),
            Hidden::make('in_reply_to_email_id'),

            Section::make('Privacy')
                ->collapsed()
                ->schema([
                    Select::make('privacy_tier')
                        ->label(__('filament/pages/email-inbox.reply_form.privacy.label'))
                        ->helperText(__('filament/pages/email-inbox.reply_form.privacy.helper_text'))
                        ->options(EmailPrivacyTier::class)
                        ->default(fn (): string => $this->defaultPrivacyTier()->value)
                        ->required(),
                ]),

            Placeholder::make('quoted_body_preview')
                ->hiddenLabel()
                ->content(function (Get $get): HtmlString {
                    $isForward = $get('mode') === 'forward';
                    $label = $isForward ? 'Forwarded message' : 'Original message';
                    $safeQuotedHtml = EmailHtmlSanitizer::sanitize($get('quoted_body_html')) ?? '';

                    return new HtmlString(
                        '<div x-data="{ open: false }" class="mt-1">'
                        .'<div class="flex items-center gap-3 cursor-pointer select-none" @click="open = !open">'
                        .'<div class="h-px flex-1 bg-gray-200 dark:bg-gray-700"></div>'
                        .'<span class="flex items-center gap-1 shrink-0 text-xs text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">'
                        .'<svg x-bind:class="open && \'rotate-90\'" class="h-3 w-3 transition-transform duration-150" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/></svg>'
                        .$label
                        .'</span>'
                        .'<div class="h-px flex-1 bg-gray-200 dark:bg-gray-700"></div>'
                        .'</div>'
                        .'<div x-show="open" x-collapse class="mt-2 overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-white/25 dark:bg-neutral-900">'
                        .'<iframe srcdoc="'.e($safeQuotedHtml).'" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" class="block w-full border-0 bg-white [color-scheme:light] dark:bg-neutral-900 dark:[color-scheme:dark]" style="height:20rem"></iframe>'
                        .'</div>'
                        .'</div>'
                    );
                })
                ->visible(fn (Get $get): bool => filled($get('quoted_body_html'))),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     connected_account_id: string,
     *     subject: string,
     *     body_html: string,
     *     to: array<array{email: string, name: null}>,
     *     cc: array<array{email: string, name: null}>,
     *     bcc: array<array{email: string, name: null}>,
     *     in_reply_to_email_id: string|null,
     *     creation_source: EmailCreationSource,
     *     privacy_tier: EmailPrivacyTier,
     *     batch_id: null,
     * }
     */
    private function buildSendData(array $data, EmailCreationSource $source): array
    {
        $renderer = resolve(EmailTemplateRenderService::class);

        return [
            'connected_account_id' => $data['connected_account_id'],
            'subject' => $renderer->renderContent((string) $data['subject']),
            'body_html' => $renderer->renderForSending((string) $data['body_html']),
            'to' => array_map(fn (string $email): array => ['email' => $email, 'name' => null], $data['to'] ?? []),
            'cc' => array_map(fn (string $email): array => ['email' => $email, 'name' => null], $data['cc'] ?? []),
            'bcc' => array_map(fn (string $email): array => ['email' => $email, 'name' => null], $data['bcc'] ?? []),
            'in_reply_to_email_id' => $data['in_reply_to_email_id'] ?? null,
            'creation_source' => $source,
            'privacy_tier' => $this->resolvePrivacyTier($data['privacy_tier'] ?? null),
            'batch_id' => null,
            'attachments' => $data['attachments'] ?? [],
            'attachment_file_names' => $data['attachment_file_names'] ?? [],
        ];
    }

    private function defaultPrivacyTier(): EmailPrivacyTier
    {
        return resolve(PrivacyService::class)->defaultTierForUser($this->authUser());
    }

    private function resolvePrivacyTier(mixed $value): EmailPrivacyTier
    {
        if ($value instanceof EmailPrivacyTier) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return EmailPrivacyTier::from($value);
        }

        return $this->defaultPrivacyTier();
    }

    /**
     * Drives the "connect a mailbox" empty state, so it is read from the blade
     * as a computed property.
     */
    #[Computed]
    public function hasActiveConnectedAccount(): bool
    {
        /** @var Team|null $team */
        $team = filament()->getTenant();

        return ConnectedAccount::hasActiveFor($this->authUser(), $team);
    }

    /**
     * Take the whole page over with the connect prompt only when the user has nothing
     * to read here: teammates without a mailbox of their own still get the inbox for
     * emails shared with them.
     */
    #[Computed]
    public function showConnectPrompt(): bool
    {
        if ($this->hasActiveConnectedAccount()) {
            return false;
        }

        $user = $this->authUser();

        return Email::query()
            ->forTeam($user->current_team_id)
            ->withGlobalScope('visible', new VisibleEmailScope($user))
            ->doesntExist();
    }

    /**
     * @return list<string>
     */
    private function contactEmailSuggestions(): array
    {
        $teamId = filament()->getTenant()?->getKey();

        /** @var list<string> */
        return EmailParticipant::query()
            // Drafts are private (never-sent, PRIVATE tier). Without this, a
            // teammate's still-unsent draft leaks its to/cc/bcc addresses into
            // everyone else's recipient autocomplete via this team-wide query.
            ->whereHas('email', fn (Builder $q): Builder => $q
                ->where('team_id', $teamId)
                ->where('status', '!=', EmailStatus::DRAFT))
            ->whereNotNull('email_address')
            ->select('email_address')
            ->distinct()
            ->orderBy('email_address')
            ->limit(300)
            ->pluck('email_address')
            ->values()
            ->all();
    }

    /**
     * The user's active connected accounts for the current team, default first,
     * keyed by id. Cached per request so the switcher and list filter share it.
     *
     * @return Collection<string, ConnectedAccount>
     */
    private function userActiveAccounts(): Collection
    {
        /** @var Collection<string, ConnectedAccount> */
        return once(fn (): Collection => ConnectedAccount::query()
            ->where('user_id', $this->authUser()->getKey())
            ->where('team_id', filament()->getTenant()?->getKey())
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->oldest()
            ->get()
            ->keyBy('id'));
    }

    /**
     * @return array<string, string>
     */
    private function activeAccountOptions(): array
    {
        return $this->userActiveAccounts()
            ->mapWithKeys(fn (ConnectedAccount $account): array => [$account->getKey() => $account->label])
            ->all();
    }

    /**
     * Switcher options: every active account plus an "All accounts" entry. Shown
     * only when the user has more than one account ({@see $this->accountId}).
     *
     * @return array<string, string>
     */
    #[Computed]
    public function accountFilterOptions(): array
    {
        return [
            ...$this->activeAccountOptions(),
            'all' => __('filament/pages/email-inbox.account_filter.all'),
        ];
    }

    #[Computed]
    public function showAccountSwitcher(): bool
    {
        return $this->userActiveAccounts()->count() > 1;
    }

    private function authUser(): User
    {
        /** @var User */
        return auth()->user();
    }
}
