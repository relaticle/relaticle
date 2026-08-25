<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use App\Models\Team;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\View\View;
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
use Relaticle\EmailIntegration\Actions\RequestEmailAccessAction;
use Relaticle\EmailIntegration\Actions\SendEmailAction;
use Relaticle\EmailIntegration\Actions\UpdateEmailSharingAction;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Enums\EmailPageTab;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailFeatureFlag;
use Relaticle\EmailIntegration\Filament\RichContent\SignatureBlock;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\EmailSignature;
use Relaticle\EmailIntegration\Models\EmailTemplate;
use Relaticle\EmailIntegration\Models\EmailThread;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;
use Relaticle\EmailIntegration\Services\EmailTemplateRenderService;
use Relaticle\EmailIntegration\Services\EmailThreadSummaryService;
use Relaticle\EmailIntegration\Services\PrivacyService;
use Relaticle\EmailIntegration\Support\EmailHtmlSanitizer;

final class EmailInboxPage extends Page
{
    use HasEmailFeatureFlag;
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
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * No page heading. The board is the page — a title bar above it repeating the word
     * already highlighted in the sidebar only pushes the list down. Filament drops the
     * whole header block when the heading and header actions are both empty, which is
     * why Compose moved onto the board's own toolbar.
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
            // row's can('viewSubject'/'viewBody') hits) — eager-load them to avoid 2 lazy
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
        // applies neither — without this, VisibleEmailScope's owner clause
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
     * it — the detail pane shows an inline approve/deny strip for these.
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
     * Badge counts for the tab bar. Drafts are the user's own unsent messages and
     * the outbox counts what is still waiting to go out; templates count what the
     * user may actually apply (shared, or their own).
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
                ->whereIn('status', [EmailStatus::QUEUED, EmailStatus::FAILED])
                ->count(),
            EmailPageTab::TEMPLATES->value => EmailTemplate::query()
                ->where('team_id', $teamId)
                ->where(fn (Builder $q): Builder => $q
                    ->where('is_shared', true)
                    ->orWhere('created_by', $user->getKey()))
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

    protected function composeEmailAction(): Action
    {
        return Action::make('composeEmail')
            ->label(__('filament/pages/email-inbox.compose.label'))
            // Sits in the board toolbar, so it matches the height of the controls
            // beside it rather than the smaller page-header default.
            ->size(Size::Medium)
            ->slideOver()
            ->icon('heroicon-o-pencil-square')
            ->modalWidth(Width::SevenExtraLarge)
            ->keyBindings(['command+e', 'ctrl+e'])
            ->tooltip('⌘ + e')
            ->visible(fn (): bool => $this->hasActiveConnectedAccount())
            ->fillForm(function (): array {
                // Default to the account currently filtered in the inbox; fall back
                // to the first active account when viewing "all" or none selected.
                $accounts = $this->userActiveAccounts();
                $account = $accounts->get($this->accountId) ?? $accounts->first();

                if ($account === null) {
                    return [];
                }

                $signature = EmailSignature::query()
                    ->where('connected_account_id', $account->getKey())
                    ->where('is_default', true)
                    ->first();

                return [
                    'connected_account_id' => $account->getKey(),
                    'signature_id' => $signature?->getKey(),
                    'body_html' => resolve(EmailTemplateRenderService::class)
                        ->applySignatureBlock('<p></p>', $signature),
                    'privacy_tier' => $this->defaultPrivacyTier()->value,
                ];
            })
            ->schema($this->composeFormSchema())
            ->action(function (array $data): void {
                resolve(SendEmailAction::class)->execute(
                    data: $this->buildSendData($data, EmailCreationSource::COMPOSE),
                );

                Notification::make()
                    ->title(__('filament/pages/email-inbox.compose.notifications.queued.title'))
                    ->body(__('filament/pages/email-inbox.compose.notifications.queued.body'))
                    ->success()
                    ->send();
            });
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

    protected function manageSharingAction(): Action
    {
        return Action::make('manageSharing')
            ->label(__('filament/pages/email-inbox.sharing.label'))
            ->icon('ri-share-line')
            ->color('gray')
            ->iconButton()
            ->extraAttributes(['class' => 'fi-email-reader-action'])
            ->tooltip(__('filament/pages/email-inbox.sharing.label'))
            ->modalHeading(__('filament/pages/email-inbox.sharing.modal_heading'))
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Save')
            ->schema([
                // Two halves of one decision, side by side: what the workspace at large
                // gets, and who is named individually. Stacked, the second half reads as
                // an afterthought below the fold.
                Grid::make(['default' => 1, 'md' => 12])
                    ->schema([
                        // Cards rather than a select: each tier is a decision about who
                        // sees what, which a dropdown of four nouns hides. Same cards as
                        // the account settings page.
                        Section::make(__('filament/pages/email-inbox.sharing.fields.privacy_tier.label'))
                            ->icon('heroicon-o-globe-alt')
                            ->compact()
                            ->columnSpan(['default' => 1, 'md' => 5])
                            ->schema([
                                Radio::make('privacy_tier')
                                    ->hiddenLabel()
                                    ->options(EmailPrivacyTier::class)
                                    ->view('email-integration::forms.sharing-tier-cards')
                                    ->viewData(['ariaLabel' => __('filament/pages/email-inbox.sharing.fields.privacy_tier.label')])
                                    ->required(),
                            ]),

                        Section::make(__('filament/pages/email-inbox.sharing.fields.shares.label'))
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
                                    ->itemLabel(fn (array $state): string => $this->sharingRowLabel($state))
                                    ->columns(2)
                                    ->schema([
                                        Select::make('shared_with')
                                            ->label(__('filament/pages/email-inbox.sharing.fields.shared_with.label'))
                                            ->hiddenLabel()
                                            ->placeholder(__('filament/pages/email-inbox.sharing.fields.shared_with.placeholder'))
                                            ->options(fn (): array => $this->teammateOptions())
                                            ->multiple()
                                            ->searchable()
                                            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                            ->required()
                                            ->distinct(),

                                        Select::make('tier')
                                            ->label(__('filament/pages/email-inbox.sharing.fields.tier.label'))
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
                        ->groupBy('tier')
                        ->map(fn (Collection $shares, string $tier): array => [
                            'shared_with' => $shares->pluck('shared_with')->all(),
                            'tier' => $tier,
                        ])
                        ->values()
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
                    $this->flattenSharingRows($data['shares'] ?? []),
                );

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/email-inbox.sharing.notifications.saved.title'))
                    ->send();
            });
    }

    /**
     * @param  array{
     *     shared_with?: array<int, string|int>|string|int|null,
     *     tier?: string|null,
     * }  $state
     */
    private function sharingRowLabel(array $state): string
    {
        $sharedWith = $state['shared_with'] ?? [];
        $teammateIds = is_array($sharedWith) ? $sharedWith : [$sharedWith];

        $names = collect($teammateIds)
            ->map(fn (string|int|null $teammateId): ?string => $this->teammateOptions()[(string) $teammateId] ?? null)
            ->filter()
            ->values();

        if ($names->isEmpty()) {
            return __('filament/pages/email-inbox.sharing.fields.shares.new_item');
        }

        return $names->join(', ');
    }

    /**
     * @param  array<int, array{
     *     shared_with?: array<int, string|int>|string|int|null,
     *     tier?: string|EmailPrivacyTier|null,
     * }>  $shares
     * @return array<int, array{shared_with: string|int, tier: string|EmailPrivacyTier}>
     */
    private function flattenSharingRows(array $shares): array
    {
        return collect($shares)
            ->flatMap(function (array $share): array {
                if (! isset($share['tier'])) {
                    return [];
                }

                $sharedWith = $share['shared_with'] ?? [];
                $teammateIds = is_array($sharedWith) ? $sharedWith : [$sharedWith];

                return collect($teammateIds)
                    ->filter()
                    ->map(fn (string|int $teammateId): array => [
                        'shared_with' => $teammateId,
                        'tier' => $share['tier'],
                    ])
                    ->all();
            })
            ->values()
            ->all();
    }

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
            ->label(__('filament/pages/email-inbox.summarize_thread.label'))
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->iconButton()
            ->extraAttributes(['class' => 'fi-email-reader-action'])
            ->tooltip(__('filament/pages/email-inbox.summarize_thread.label'))
            ->modalHeading(__('filament/pages/email-inbox.summarize_thread.modal_heading'))
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
            ->label(__('filament/pages/email-inbox.request_access.label'))
            ->icon('heroicon-o-key')
            ->color('gray')
            ->iconButton()
            ->extraAttributes(['class' => 'fi-email-reader-action'])
            ->tooltip(__('filament/pages/email-inbox.request_access.label'))
            ->schema([
                Select::make('tier_requested')
                    ->label(__('filament/pages/email-inbox.request_access.fields.tier_requested.label'))
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
                        ->title(__('filament/pages/email-inbox.request_access.notifications.pending.title'))
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/email-inbox.request_access.notifications.sent.title'))
                    ->send();
            });
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
    private function composeFormSchema(): array
    {
        return [
            Grid::make(2)
                ->schema([
                    Select::make('connected_account_id')
                        ->label(__('filament/pages/email-inbox.compose_form.from.label'))
                        ->options(fn (): array => $this->activeAccountOptions())
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                            if ($state === null) {
                                return;
                            }

                            $sig = EmailSignature::query()
                                ->where('connected_account_id', $state)
                                ->where('is_default', true)
                                ->first();

                            $set('signature_id', $sig?->getKey());

                            // Swap the signature block for the new account's default,
                            // keeping whatever the user has already typed.
                            $body = (string) ($get('body_html') ?? '<p></p>');
                            $set('body_html', resolve(EmailTemplateRenderService::class)
                                ->applySignatureBlock($body !== '' ? $body : '<p></p>', $sig));
                        }),

                    Select::make('template_id')
                        ->label(__('filament/pages/email-inbox.compose_form.template.label'))
                        ->placeholder(__('filament/pages/email-inbox.compose_form.template.placeholder'))
                        ->options(fn (): array => $this->templateOptions())
                        ->live()
                        ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                            if ($state === null) {
                                return;
                            }

                            /** @var EmailTemplate|null $template */
                            $template = EmailTemplate::query()
                                ->where('team_id', filament()->getTenant()?->getKey())
                                ->whereKey($state)
                                ->first();

                            if ($template === null) {
                                return;
                            }

                            // Keep the signature below the template body so picking a
                            // template never wipes the user's signature.
                            $sig = $this->resolveComposeSignature(
                                $get('connected_account_id'),
                                $get('signature_id'),
                            );

                            $rendered = resolve(EmailTemplateRenderService::class)
                                ->renderWithSignature($template, null, $sig);

                            if ($sig instanceof EmailSignature) {
                                $set('signature_id', $sig->getKey());
                            }

                            $set('subject', $rendered['subject']);
                            $set('body_html', $rendered['body_html']);
                        }),
                ]),

            TagsInput::make('to')
                ->label(__('filament/pages/email-inbox.compose_form.to.label'))
                ->placeholder(__('filament/pages/email-inbox.compose_form.to.placeholder'))
                ->required()
                ->splitKeys(['Tab', ',', ' '])
                ->suggestions(fn (): array => $this->contactEmailSuggestions()),

            Grid::make(2)
                ->schema([
                    TagsInput::make('cc')
                        ->label(__('filament/pages/email-inbox.compose_form.cc.label'))
                        ->placeholder(__('filament/pages/email-inbox.compose_form.cc.placeholder'))
                        ->splitKeys(['Tab', ',', ' '])
                        ->suggestions(fn (): array => $this->contactEmailSuggestions()),

                    TagsInput::make('bcc')
                        ->label(__('filament/pages/email-inbox.compose_form.bcc.label'))
                        ->placeholder(__('filament/pages/email-inbox.compose_form.bcc.placeholder'))
                        ->splitKeys(['Tab', ',', ' '])
                        ->suggestions(fn (): array => $this->contactEmailSuggestions()),
                ]),

            TextInput::make('subject')
                ->required()
                ->maxLength(255),

            RichEditor::make('body_html')
                ->label(__('filament/pages/email-inbox.compose_form.body.label'))
                ->required()
                ->mergeTags(EmailTemplateRenderService::MERGE_TAGS)
                ->customBlocks([SignatureBlock::class])
                ->toolbarButtons([
                    'bold', 'italic', 'underline', 'strike',
                    'link', 'bulletList', 'orderedList',
                    'blockquote', 'h2', 'h3', 'undo', 'redo',
                ]),

            Section::make('Attachments')
                ->collapsed()
                ->schema([
                    FileUpload::make('attachments')
                        ->hiddenLabel()
                        ->multiple()
                        ->visibility('private')
                        ->disk('local')
                        ->directory('email-attachments')
                        ->maxSize(10240)
                        ->storeFileNamesIn('attachment_file_names')
                        ->nullable(),
                ]),

            Section::make('Settings')
                ->description(__('filament/pages/email-inbox.compose_form.settings.description'))
                ->icon('heroicon-o-cog-6-tooth')
                ->collapsed()
                ->schema([
                    Select::make('privacy_tier')
                        ->label(__('filament/pages/email-inbox.compose_form.privacy.label'))
                        ->helperText(__('filament/pages/email-inbox.compose_form.privacy.helper_text'))
                        ->options(EmailPrivacyTier::class)
                        ->default(fn (): string => $this->defaultPrivacyTier()->value)
                        ->required(),

                    Select::make('signature_id')
                        ->label(__('filament/pages/email-inbox.compose_form.signature.label'))
                        ->placeholder(__('filament/pages/email-inbox.compose_form.signature.placeholder'))
                        ->options(fn (Get $get): array => EmailSignature::query()
                            ->where('connected_account_id', $get('connected_account_id'))
                            ->pluck('name', 'id')
                            ->all()
                        )
                        ->live()
                        ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                            // A null state means "no signature" — strip the block.
                            $sig = filled($state)
                                ? EmailSignature::query()->whereKey($state)->first()
                                : null;

                            $body = (string) ($get('body_html') ?? '<p></p>');
                            $set('body_html', resolve(EmailTemplateRenderService::class)
                                ->applySignatureBlock($body !== '' ? $body : '<p></p>', $sig));
                        }),
                ]),
        ];
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
     * Drives both the compose action and the "connect a mailbox" empty state,
     * so it is read from the blade as a computed property.
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
            // Drafts are private (never-sent, PRIVATE tier) — without this, a
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
     * Resolve the signature to attach when composing: the explicitly selected
     * one, else the chosen account's default, else the active account's default.
     */
    private function resolveComposeSignature(?string $accountId, ?string $signatureId): ?EmailSignature
    {
        if (filled($signatureId)) {
            return EmailSignature::query()->whereKey($signatureId)->first();
        }

        if (blank($accountId)) {
            $accountId = ConnectedAccount::query()
                ->where('user_id', $this->authUser()->getKey())
                ->where('team_id', filament()->getTenant()?->getKey())
                ->where('status', 'active')
                ->value('id');
        }

        if (blank($accountId)) {
            return null;
        }

        return EmailSignature::query()
            ->where('connected_account_id', $accountId)
            ->where('is_default', true)
            ->first();
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

    /**
     * @return array<string, string>
     */
    private function templateOptions(): array
    {
        return EmailTemplate::query()
            ->where(fn (Builder $q): Builder => $q
                ->where('team_id', filament()->getTenant()?->getKey())
                ->where(fn (Builder $q2): Builder => $q2
                    ->where('is_shared', true)
                    ->orWhere('created_by', $this->authUser()->getKey())
                )
            )
            ->pluck('name', 'id')
            ->all();
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

    private function authUser(): User
    {
        /** @var User */
        return auth()->user();
    }
}
