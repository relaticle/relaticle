<?php

declare(strict_types=1);

namespace App\Filament\Pages;

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
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Relaticle\EmailIntegration\Actions\ApproveEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\DenyEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\SendEmailAction;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Models\EmailSignature;
use Relaticle\EmailIntegration\Models\EmailTemplate;
use Relaticle\EmailIntegration\Models\EmailThread;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;
use Relaticle\EmailIntegration\Notifications\EmailAccessRequestedNotification;
use Relaticle\EmailIntegration\Services\EmailSharingService;
use Relaticle\EmailIntegration\Services\EmailTemplateRenderService;
use Relaticle\EmailIntegration\Services\EmailThreadSummaryService;
use Relaticle\EmailIntegration\Services\PrivacyService;

final class EmailInboxPage extends Page
{
    use WithPagination;

    protected string $view = 'filament.pages.email-inbox';

    protected static ?string $navigationLabel = 'Email';

    protected static ?string $title = 'Email';

    protected static ?string $slug = 'email';

    protected static string|\UnitEnum|null $navigationGroup = 'Emails';

    protected static ?int $navigationSort = 1;

    public EmailFolder $folder = EmailFolder::Inbox;

    #[Url(as: 'email')]
    public ?string $selectedEmailId = null;

    public string $search = '';

    public function mount(): void
    {
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
            ->forTeam($user->current_team_id)
            ->withGlobalScope('visible', new VisibleEmailScope($user));

        if ($this->folder === EmailFolder::Sent) {
            $query->where('direction', EmailDirection::OUTBOUND);
        } elseif ($this->folder === EmailFolder::Inbox) {
            $query->where('direction', EmailDirection::INBOUND);
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

        return Email::query()
            ->forTeam($user->current_team_id)
            ->withGlobalScope('visible', new VisibleEmailScope($user))
            ->where('direction', EmailDirection::INBOUND)
            ->whereNull('read_at')
            ->count();
    }

    public function selectEmail(string $id): void
    {
        $this->selectedEmailId = $id;

        Email::query()
            ->whereKey($id)
            ->where('user_id', $this->authUser()->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        unset($this->inboxUnreadCount);
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
            ->label('Compose')
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
                    'body_html' => $signature !== null ? '<p></p><hr>'.$signature->content_html : '',
                    'privacy_tier' => $this->defaultPrivacyTier()->value,
                ];
            })
            ->schema($this->composeFormSchema())
            ->action(function (array $data): void {
                resolve(SendEmailAction::class)->execute(
                    data: $this->buildSendData($data, EmailCreationSource::COMPOSE),
                );

                Notification::make()
                    ->title('Email queued')
                    ->body('Your email is being sent.')
                    ->success()
                    ->send();
            });
    }

    protected function replyForwardEmailAction(): Action
    {
        return Action::make('replyForwardEmail')
            ->slideOver()
            ->modalHeading(fn (array $arguments): string => match ($arguments['mode'] ?? 'reply') {
                'reply_all' => 'Reply All',
                'forward' => 'Forward',
                default => 'Reply',
            })
            ->modalWidth(Width::SevenExtraLarge)
            ->fillForm(function (array $arguments): array {
                /** @var Email|null $email */
                $email = Email::query()->with(['participants', 'body'])->whereKey($arguments['emailId'] ?? null)->first();

                if ($email === null) {
                    return [];
                }

                $mode = $arguments['mode'] ?? 'reply';

                $account = ConnectedAccount::query()
                    ->where('user_id', $this->authUser()->getKey())
                    ->where('team_id', filament()->getTenant()?->getKey())
                    ->where('status', 'active')
                    ->first();

                $toParticipants = match ($mode) {
                    'forward' => [],
                    'reply_all' => $email->participants
                        ->where('role', '!=', 'from')
                        ->pluck('email_address')
                        ->all(),
                    default => $email->participants
                        ->where('role', 'from')
                        ->pluck('email_address')
                        ->all(),
                };

                $subjectPrefix = $mode === 'forward' ? 'Fwd: ' : 'Re: ';

                return [
                    'connected_account_id' => $account?->getKey(),
                    'to' => $toParticipants,
                    'subject' => $subjectPrefix.($email->subject ?? ''),
                    'body_html' => '',
                    'quoted_body_html' => $email->body?->body_html,
                    'mode' => $mode,
                    'in_reply_to_email_id' => $mode !== 'forward' ? $email->getKey() : null,
                    'privacy_tier' => $this->defaultPrivacyTier()->value,
                ];
            })
            ->schema($this->replyFormSchema())
            ->action(function (array $data, array $arguments): void {
                $mode = $arguments['mode'] ?? 'reply';

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

                Notification::make()->title('Email queued')->success()->send();
            });
    }

    protected function manageSharingAction(): Action
    {
        return Action::make('manageSharing')
            ->label('Sharing')
            ->icon('heroicon-o-lock-open')
            ->modalHeading('Sharing settings')
            ->modalSubmitActionLabel('Save')
            ->schema([
                Select::make('privacy_tier')
                    ->label('Who can see this email?')
                    ->options(EmailPrivacyTier::class)
                    ->required(),

                Repeater::make('shares')
                    ->label('Share with specific teammates')
                    ->defaultItems(0)
                    ->addActionLabel('Add teammate')
                    ->columns(2)
                    ->schema([
                        Select::make('shared_with')
                            ->label('Teammate')
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
                            ->label('Access level')
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
            ->action(function (array $data, array $arguments, EmailSharingService $sharingService): void {
                $email = $this->resolveTeamEmail($arguments['emailId'] ?? null, 'share');

                abort_if(! $email instanceof Email, 403);

                $sharer = $this->authUser();

                $sharingService->setEmailTier($email, $data['privacy_tier']);
                $email->shares()->where('shared_by', $sharer->getKey())->delete();

                foreach ($data['shares'] ?? [] as $share) {
                    $sharedWithUser = User::query()
                        ->inTeam($sharer->current_team_id)
                        ->whereKey($share['shared_with'])
                        ->first();

                    abort_if($sharedWithUser === null, 403);

                    $sharingService->shareEmail(
                        $email,
                        $sharer,
                        $sharedWithUser,
                        EmailPrivacyTier::from($share['tier']),
                    );
                }

                Notification::make()
                    ->success()
                    ->title('Sharing settings saved.')
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

    protected function summarizeThreadAction(): Action
    {
        return Action::make('summarizeThread')
            ->label('Summarize Thread')
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->visible(false)
            ->modalHeading('AI Thread Summary')
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
            ->label('Request Access')
            ->icon('heroicon-o-key')
            ->schema([
                Select::make('tier_requested')
                    ->label('Access level requested')
                    ->options([
                        EmailPrivacyTier::SUBJECT->value => EmailPrivacyTier::SUBJECT->getLabel(),
                        EmailPrivacyTier::FULL->value => EmailPrivacyTier::FULL->getLabel(),
                    ])
                    ->required(),
            ])
            ->action(function (array $data, array $arguments): void {
                $email = $this->resolveTeamEmail($arguments['emailId'] ?? null, 'requestAccess');

                abort_if(! $email instanceof Email, 403);

                $requester = $this->authUser();

                $existing = EmailAccessRequest::query()
                    ->where('email_id', $email->getKey())
                    ->where('requester_id', $requester->getKey())
                    ->where('status', 'pending')
                    ->exists();

                if ($existing) {
                    Notification::make()
                        ->warning()
                        ->title('You already have a pending request for this email.')
                        ->send();

                    return;
                }

                $request = EmailAccessRequest::query()->create([
                    'email_id' => $email->getKey(),
                    'requester_id' => $requester->getKey(),
                    'owner_id' => $email->user_id,
                    'tier_requested' => $data['tier_requested'],
                    'status' => 'pending',
                ]);

                $email->user?->notify(new EmailAccessRequestedNotification($request));

                Notification::make()
                    ->success()
                    ->title('Access request sent.')
                    ->send();
            });
    }

    protected function approveAccessRequestAction(): Action
    {
        return Action::make('approveAccessRequest')
            ->requiresConfirmation()
            ->modalHeading('Approve access request')
            ->modalDescription(fn (array $arguments): string => sprintf(
                'Grant %s access to this email?',
                EmailAccessRequest::query()->whereKey($arguments['requestId'] ?? null)->first()?->requester->name ?? 'this user',
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
                    ->title('Access request approved.')
                    ->send();
            });
    }

    protected function denyAccessRequestAction(): Action
    {
        return Action::make('denyAccessRequest')
            ->requiresConfirmation()
            ->modalHeading('Deny access request')
            ->modalDescription(fn (array $arguments): string => sprintf(
                'Deny %s\'s request for access to this email?',
                EmailAccessRequest::query()->whereKey($arguments['requestId'] ?? null)->first()?->requester->name ?? 'this user',
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
                    ->title('Access request denied.')
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
                        ->label('From')
                        ->options(fn (): array => $this->activeAccountOptions())
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            if ($state === null) {
                                return;
                            }

                            $sig = EmailSignature::query()
                                ->where('connected_account_id', $state)
                                ->where('is_default', true)
                                ->first();

                            $set('signature_id', $sig?->getKey());

                            if ($sig !== null) {
                                $set('body_html', '<p></p><hr>'.$sig->content_html);
                            }
                        }),

                    Select::make('template_id')
                        ->label('Template')
                        ->placeholder('Apply a template…')
                        ->options(fn (): array => $this->templateOptions())
                        ->live()
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            if ($state === null) {
                                return;
                            }

                            /** @var EmailTemplate|null $template */
                            $template = EmailTemplate::query()->whereKey($state)->first();

                            if ($template === null) {
                                return;
                            }

                            $rendered = resolve(EmailTemplateRenderService::class)
                                ->render($template);

                            $set('subject', $rendered['subject']);
                            $set('body_html', $rendered['body_html']);
                        }),
                ]),

            TagsInput::make('to')
                ->label('To')
                ->placeholder('email@example.com')
                ->required()
                ->splitKeys(['Tab', ',', ' '])
                ->suggestions(fn (): array => $this->contactEmailSuggestions()),

            Grid::make(2)
                ->schema([
                    TagsInput::make('cc')
                        ->label('CC')
                        ->placeholder('email@example.com')
                        ->splitKeys(['Tab', ',', ' '])
                        ->suggestions(fn (): array => $this->contactEmailSuggestions()),

                    TagsInput::make('bcc')
                        ->label('BCC')
                        ->placeholder('email@example.com')
                        ->splitKeys(['Tab', ',', ' '])
                        ->suggestions(fn (): array => $this->contactEmailSuggestions()),
                ]),

            TextInput::make('subject')
                ->required()
                ->maxLength(255),

            RichEditor::make('body_html')
                ->label('Body')
                ->required()
                ->mergeTags(EmailTemplateRenderService::MERGE_TAGS)
                ->toolbarButtons([
                    'bold', 'italic', 'underline', 'strike',
                    'link', 'bulletList', 'orderedList',
                    'blockquote', 'h2', 'h3', 'undo', 'redo',
                ]),

            Section::make('Privacy')
                ->collapsed()
                ->schema([
                    Select::make('privacy_tier')
                        ->label('Who can see this email?')
                        ->helperText('Defaults to your team or personal sharing setting.')
                        ->options(EmailPrivacyTier::class)
                        ->default(fn (): string => $this->defaultPrivacyTier()->value)
                        ->required(),
                ]),

            Section::make('Signature')
                ->collapsed()
                ->schema([
                    Select::make('signature_id')
                        ->hiddenLabel()
                        ->placeholder('No signature')
                        ->options(fn (Get $get): array => EmailSignature::query()
                            ->where('connected_account_id', $get('connected_account_id'))
                            ->pluck('name', 'id')
                            ->all()
                        )
                        ->live()
                        ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                            if ($state === null) {
                                return;
                            }

                            /** @var EmailSignature|null $sig */
                            $sig = EmailSignature::query()->whereKey($state)->first();

                            if ($sig === null) {
                                return;
                            }

                            $body = $get('body_html') ?? '';
                            $set('body_html', ($body !== '' ? $body : '<p></p>').'<hr>'.$sig->content_html);
                        }),
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
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function replyFormSchema(): array
    {
        return [
            Select::make('connected_account_id')
                ->label('From')
                ->options(fn (): array => $this->activeAccountOptions())
                ->required(),

            TagsInput::make('to')
                ->label('To')
                ->placeholder('email@example.com')
                ->required()
                ->splitKeys(['Tab', ',', ' '])
                ->suggestions(fn (): array => $this->contactEmailSuggestions()),

            Grid::make(2)
                ->schema([
                    TagsInput::make('cc')
                        ->label('CC')
                        ->placeholder('email@example.com')
                        ->splitKeys(['Tab', ',', ' '])
                        ->suggestions(fn (): array => $this->contactEmailSuggestions()),

                    TagsInput::make('bcc')
                        ->label('BCC')
                        ->placeholder('email@example.com')
                        ->splitKeys(['Tab', ',', ' '])
                        ->suggestions(fn (): array => $this->contactEmailSuggestions()),
                ]),

            TextInput::make('subject')
                ->required()
                ->maxLength(255),

            RichEditor::make('body_html')
                ->label('Message')
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
                        ->label('Who can see this email?')
                        ->helperText('Defaults to your team or personal sharing setting.')
                        ->options(EmailPrivacyTier::class)
                        ->default(fn (): string => $this->defaultPrivacyTier()->value)
                        ->required(),
                ]),

            Placeholder::make('quoted_body_preview')
                ->hiddenLabel()
                ->content(function (Get $get): HtmlString {
                    $isForward = $get('mode') === 'forward';
                    $label = $isForward ? 'Forwarded message' : 'Original message';

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
                        .'<div x-show="open" x-collapse class="mt-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-4 py-3 text-sm text-gray-500 dark:text-gray-400 prose dark:prose-invert max-w-none">'
                        .($get('quoted_body_html') ?? '')
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
            'body_html' => $renderer->renderContent((string) $data['body_html']),
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
     * @return array<string, string>
     */
    private function activeAccountOptions(): array
    {
        return ConnectedAccount::query()
            ->where('user_id', $this->authUser()->getKey())
            ->where('team_id', filament()->getTenant()?->getKey())
            ->where('status', 'active')
            ->get()
            ->mapWithKeys(fn (ConnectedAccount $account): array => [$account->getKey() => $account->label])
            ->all();
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
