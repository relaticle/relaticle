<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Relaticle\EmailIntegration\Actions\SendEmailAction;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\EmailSignature;
use Relaticle\EmailIntegration\Models\EmailTemplate;
use Relaticle\EmailIntegration\Services\EmailTemplateRenderService;

trait HasEmailComposeActions
{
    /**
     * Return the CRM record these emails belong to (People, Company, or Opportunity).
     */
    abstract protected function getCrmRecord(): Model;

    public function openReplyModal(string $emailId, string $mode): void
    {
        $this->mountAction('replyForwardEmail', [
            'emailId' => $emailId,
            'mode' => $mode,
        ]);
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
                    ->where('user_id', $this->getAuthenticatedUser()->getKey())
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
                    'body_html' => $signature !== null ? '<br><hr><br>'.$signature->content_html : '',
                ];
            })
            ->schema($this->composeFormSchema())
            ->action(function (array $data): void {
                $record = $this->getCrmRecord();

                resolve(SendEmailAction::class)->execute(
                    data: $this->buildSendData($data, EmailCreationSource::COMPOSE),
                    linkToType: $record::class,
                    linkToId: $record->getKey(),
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
            ->tooltip(fn (array $arguments): string => match ($arguments['mode'] ?? 'reply') {
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
                    ->where('user_id', $this->getAuthenticatedUser()->getKey())
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
                    'quoted_body_html' => $email->body?->body_html ?? '',
                    'mode' => $mode,
                    'in_reply_to_email_id' => $mode !== 'forward' ? $email->getKey() : null,
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

                $record = $this->getCrmRecord();

                resolve(SendEmailAction::class)->execute(
                    data: $this->buildSendData($data, $source),
                    linkToType: $record::class,
                    linkToId: $record->getKey(),
                );

                Notification::make()->title('Email queued')->success()->send();
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
                                $set('body_html', '<br><hr><br>'.$sig->content_html);
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
                                ->render($template, $this->getCrmRecord());

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
                ->toolbarButtons([
                    'bold', 'italic', 'underline', 'strike',
                    'link', 'bulletList', 'orderedList',
                    'blockquote', 'h2', 'h3', 'undo', 'redo',
                ]),

            Section::make('Signature & attachments')
                ->collapsed()
                ->schema([
                    Grid::make(2)
                        ->schema([
                            Select::make('signature_id')
                                ->label('Signature')
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
                                    $set('body_html', $body.'<br><hr><br>'.$sig->content_html);
                                }),

                            FileUpload::make('attachments')
                                ->label('Attachments')
                                ->multiple()
                                ->visibility('private')
                                ->disk('local')
                                ->directory('email-attachments')
                                ->maxSize(10240)
                                ->nullable(),
                        ]),
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
                ->toolbarButtons([
                    'bold', 'italic', 'underline', 'strike',
                    'link', 'bulletList', 'orderedList',
                    'blockquote', 'h2', 'h3', 'undo', 'redo',
                ]),

            Hidden::make('quoted_body_html'),
            Hidden::make('mode'),
            Hidden::make('in_reply_to_email_id'),

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
                        .'<div x-show="open" x-collapse class="mt-2 overflow-y-auto max-h-52 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-4 py-3 text-sm text-gray-500 dark:text-gray-400 prose dark:prose-invert max-w-none">'
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
        return [
            'connected_account_id' => $data['connected_account_id'],
            'subject' => $data['subject'],
            'body_html' => $data['body_html'],
            'to' => array_map(fn (string $email): array => ['email' => $email, 'name' => null], $data['to'] ?? []),
            'cc' => array_map(fn (string $email): array => ['email' => $email, 'name' => null], $data['cc'] ?? []),
            'bcc' => array_map(fn (string $email): array => ['email' => $email, 'name' => null], $data['bcc'] ?? []),
            'in_reply_to_email_id' => $data['in_reply_to_email_id'] ?? null,
            'creation_source' => $source,
            'privacy_tier' => EmailPrivacyTier::FULL,
            'batch_id' => null,
        ];
    }

    private function hasActiveConnectedAccount(): bool
    {
        return ConnectedAccount::query()
            ->where('user_id', $this->getAuthenticatedUser()->getKey())
            ->where('team_id', filament()->getTenant()?->getKey())
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Returns known email addresses from this team's email history as autocomplete suggestions.
     *
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
            ->where('user_id', $this->getAuthenticatedUser()->getKey())
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
                    ->orWhere('created_by', $this->getAuthenticatedUser()->getKey())
                )
            )
            ->pluck('name', 'id')
            ->all();
    }

    private function getAuthenticatedUser(): User
    {
        /** @var User */
        return auth()->user();
    }
}
