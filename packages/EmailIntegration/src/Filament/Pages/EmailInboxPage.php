<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
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
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailFeatureFlag;
use Relaticle\EmailIntegration\Filament\RichContent\SignatureBlock;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\EmailShare;
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
        $this->ensureEmailSelected();
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
        $this->ensureEmailSelected();
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return ['reply-email' => 'openReplyModal'];
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
        return [
            $this->composeEmailAction(),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, Email>
     */
    #[Computed]
    public function emails(): LengthAwarePaginator
    {
        $user = $this->authUser();

        $query = Email::query()
            ->with(['from', 'labels'])
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

    public function selectEmail(string $id): void
    {
        $this->selectedEmailId = $id;

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

    public function setFolder(string $folder): void
    {
        $this->folder = EmailFolder::from($folder);
        $this->search = '';
        $this->selectedEmailId = null;
        $this->resetPage();
        unset($this->emails);
        $this->ensureEmailSelected();
    }

    private function ensureEmailSelected(): void
    {
        if ($this->selectedEmailId !== null) {
            return;
        }

        $first = $this->emails()->first();

        if ($first === null) {
            return;
        }

        $this->selectEmail((string) $first->getKey());
    }

    public function deselectEmail(): void
    {
        $this->selectedEmailId = null;
        unset($this->selectedEmail);
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
            ->slideOver()
            ->icon('heroicon-o-pencil-square')
            ->modalWidth(Width::SevenExtraLarge)
            ->keyBindings(['command+e', 'ctrl+e'])
            ->tooltip('⌘ + e')
            ->visible(fn (): bool => $this->hasActiveConnectedAccount())
            ->fillForm(function (): array {
                $account = ConnectedAccount::query()
                    ->where('user_id', $this->authUser()->getKey())
                    ->where('team_id', filament()->getTenant()?->getKey())
                    ->where('status', 'active')
                    ->first();

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

    public function replyAction(): Action
    {
        return $this->replyForwardModeAction('reply', 'reply');
    }

    public function replyAllAction(): Action
    {
        return $this->replyForwardModeAction('replyAll', 'reply_all');
    }

    public function forwardAction(): Action
    {
        return $this->replyForwardModeAction('forward', 'forward');
    }

    /**
     * A single reply/reply-all/forward action with its mode baked in, for use as
     * a child of the native `<x-filament-actions::group>` dropdown. Grouped-action
     * triggers cannot carry per-action arguments, so the target email is the one
     * currently open in the detail pane ({@see $selectedEmailId}).
     */
    private function replyForwardModeAction(string $name, string $mode): Action
    {
        return Action::make($name)
            ->label($this->replyForwardLabel($mode))
            ->icon($this->replyForwardIcon($mode))
            ->modalHeading($this->replyForwardLabel($mode))
            ->slideOver()
            ->modalWidth(Width::SevenExtraLarge)
            ->fillForm(fn (): array => $this->replyForwardFormData($this->selectedEmailId, $mode))
            ->schema($this->replyFormSchema())
            ->action(function (array $data) use ($mode): void {
                $this->submitReplyForward($data, $mode);
            });
    }

    private function replyForwardIcon(string $mode): string
    {
        return match ($mode) {
            'reply_all' => 'heroicon-o-arrow-uturn-right',
            'forward' => 'heroicon-o-arrow-right',
            default => 'heroicon-o-arrow-uturn-left',
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
            ->icon('heroicon-o-lock-open')
            ->modalHeading(__('filament/pages/email-inbox.sharing.modal_heading'))
            ->modalSubmitActionLabel('Save')
            ->schema([
                Select::make('privacy_tier')
                    ->label(__('filament/pages/email-inbox.sharing.fields.privacy_tier.label'))
                    ->options(EmailPrivacyTier::class)
                    ->required(),

                Repeater::make('shares')
                    ->label(__('filament/pages/email-inbox.sharing.fields.shares.label'))
                    ->defaultItems(0)
                    ->addActionLabel(__('filament/pages/email-inbox.sharing.fields.shares.add_action_label'))
                    ->columns(2)
                    ->schema([
                        Select::make('shared_with')
                            ->label(__('filament/pages/email-inbox.sharing.fields.shared_with.label'))
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
                            ->label(__('filament/pages/email-inbox.sharing.fields.tier.label'))
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
                    ->title(__('filament/pages/email-inbox.sharing.notifications.saved.title'))
                    ->send();
            });
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

    protected function summarizeThreadAction(): Action
    {
        return Action::make('summarizeThread')
            ->label(__('filament/pages/email-inbox.summarize_thread.label'))
            ->icon('heroicon-o-sparkles')
            ->color('gray')
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
                            $template = EmailTemplate::query()->whereKey($state)->first();

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
                        .'<div x-show="open" x-collapse class="mt-2 overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700 bg-white">'
                        .'<iframe srcdoc="'.e($safeQuotedHtml).'" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" class="block w-full border-0" style="height:20rem"></iframe>'
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

    #[Computed]
    public function hasActiveConnectedAccount(): bool
    {
        return ConnectedAccount::query()
            ->where('user_id', $this->authUser()->getKey())
            ->where('team_id', filament()->getTenant()?->getKey())
            ->where('status', 'active')
            ->exists();
    }

    /**
     * @return list<string>
     */
    private function contactEmailSuggestions(): array
    {
        $teamId = filament()->getTenant()?->getKey();

        /** @var list<string> */
        return EmailParticipant::query()
            ->whereHas('email', fn (Builder $q): Builder => $q->where('team_id', $teamId))
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
