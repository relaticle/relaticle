<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Concerns;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
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
use Illuminate\Support\Facades\Date;
use Illuminate\Support\HtmlString;
use Relaticle\EmailIntegration\Actions\CancelQueuedEmailAction;
use Relaticle\EmailIntegration\Actions\SendEmailAction;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailPriority;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\RichContent\SignatureBlock;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\EmailSignature;
use Relaticle\EmailIntegration\Models\EmailTemplate;
use Relaticle\EmailIntegration\Services\EmailTemplateRenderService;
use Relaticle\EmailIntegration\Services\PrivacyService;
use Relaticle\EmailIntegration\Support\EmailHtmlSanitizer;
use RuntimeException;

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
            ->label(__('filament/concerns/email-compose.actions.compose.label'))
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
                    ->defaultFirst()
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
                $record = $this->getCrmRecord();

                $email = resolve(SendEmailAction::class)->execute(
                    data: $this->buildSendData($data, EmailCreationSource::COMPOSE),
                    linkToType: $record::class,
                    linkToId: $record->getKey(),
                );

                $this->sendQueuedNotification($email);
            });
    }

    /**
     * Legacy single action mounted by name from the email-thread reply buttons
     * (the `reply-email` browser event → {@see openReplyModal()}), where the mode
     * and target email arrive as runtime arguments.
     */
    public function replyForwardEmailAction(): Action
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

    /**
     * Build a reply/reply-all/forward action with its mode baked in, for use as a
     * child of the native `<x-filament-actions::group>` dropdown. Grouped-action
     * triggers cannot carry per-action arguments, so callers supply the target
     * email id (the one currently open in the detail pane).
     */
    protected function replyForwardModeAction(string $name, string $mode, ?string $emailId): Action
    {
        return Action::make($name)
            ->label($this->replyForwardLabel($mode))
            ->icon($this->replyForwardIcon($mode))
            ->modalHeading($this->replyForwardLabel($mode))
            ->slideOver()
            ->modalWidth(Width::SevenExtraLarge)
            ->fillForm(fn (): array => $this->replyForwardFormData($emailId, $mode))
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
            'reply_all' => 'Reply All',
            'forward' => 'Forward',
            default => 'Reply',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function replyForwardFormData(?string $emailId, string $mode): array
    {
        $email = $this->resolveComposableEmail($emailId);

        if (! $email instanceof Email) {
            return [];
        }

        $user = $this->getAuthenticatedUser();

        $account = ConnectedAccount::query()
            ->where('user_id', $user->getKey())
            ->where('team_id', filament()->getTenant()?->getKey())
            ->where('status', 'active')
            ->defaultFirst()
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
        $quotedBody = $user->can('viewBody', $email) ? ($email->body->body_html ?? '') : '';

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

        $record = $this->getCrmRecord();

        $email = resolve(SendEmailAction::class)->execute(
            data: $this->buildSendData($data, $source),
            linkToType: $record::class,
            linkToId: $record->getKey(),
        );

        $this->sendQueuedNotification($email);
    }

    private function sendQueuedNotification(Email $email): void
    {
        $notification = Notification::make()
            ->title(__('filament/concerns/email-compose.notifications.queued.title'))
            ->body(__('filament/concerns/email-compose.notifications.queued.body'))
            ->success();

        if ($email->scheduled_for !== null && $email->scheduled_for->isFuture()) {
            $notification->actions([
                Action::make('undo')
                    ->label(__('filament/concerns/email-compose.actions.undo.label'))
                    ->link()
                    ->action(function () use ($email): void {
                        try {
                            resolve(CancelQueuedEmailAction::class)->execute($email->refresh());
                            Notification::make()->title(__('filament/concerns/email-compose.notifications.cancelled.title'))->success()->send();
                        } catch (RuntimeException) {
                            Notification::make()->title(__('filament/concerns/email-compose.notifications.too_late.title'))->danger()->send();
                        }
                    }),
            ]);
        }

        $notification->send();
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
                        ->label(__('filament/concerns/email-compose.fields.from.label'))
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
                        ->label(__('filament/concerns/email-compose.fields.template.label'))
                        ->placeholder(__('filament/concerns/email-compose.fields.template.placeholder'))
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
                                ->renderWithSignature($template, $this->getCrmRecord(), $sig);

                            if ($sig !== null) {
                                $set('signature_id', $sig->getKey());
                            }

                            $set('subject', $rendered['subject']);
                            $set('body_html', $rendered['body_html']);
                        }),
                ]),

            TagsInput::make('to')
                ->label(__('filament/concerns/email-compose.fields.to.label'))
                ->placeholder(__('filament/concerns/email-compose.fields.to.placeholder'))
                ->required()
                ->splitKeys(['Tab', ',', ' '])
                ->suggestions(fn (): array => $this->contactEmailSuggestions()),

            Grid::make(2)
                ->schema([
                    TagsInput::make('cc')
                        ->label(__('filament/concerns/email-compose.fields.cc.label'))
                        ->placeholder(__('filament/concerns/email-compose.fields.cc.placeholder'))
                        ->splitKeys(['Tab', ',', ' '])
                        ->suggestions(fn (): array => $this->contactEmailSuggestions()),

                    TagsInput::make('bcc')
                        ->label(__('filament/concerns/email-compose.fields.bcc.label'))
                        ->placeholder(__('filament/concerns/email-compose.fields.bcc.placeholder'))
                        ->splitKeys(['Tab', ',', ' '])
                        ->suggestions(fn (): array => $this->contactEmailSuggestions()),
                ]),

            TextInput::make('subject')
                ->required()
                ->maxLength(255),

            RichEditor::make('body_html')
                ->label(__('filament/concerns/email-compose.fields.body.label'))
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
                ->description(__('filament/concerns/email-compose.sections.settings.description'))
                ->icon('heroicon-o-cog-6-tooth')
                ->collapsed()
                ->schema([
                    Select::make('privacy_tier')
                        ->label(__('filament/concerns/email-compose.fields.privacy_tier.label'))
                        ->helperText(__('filament/concerns/email-compose.fields.privacy_tier.helper_text'))
                        ->options(EmailPrivacyTier::class)
                        ->default(fn (): string => $this->defaultPrivacyTier()->value)
                        ->required(),

                    DateTimePicker::make('scheduled_for')
                        ->label(__('filament/concerns/email-compose.fields.scheduled_for.label'))
                        ->helperText(__('filament/concerns/email-compose.fields.scheduled_for.helper_text'))
                        ->seconds(false)
                        ->minDate(now())
                        ->nullable(),

                    Select::make('signature_id')
                        ->label(__('filament/concerns/email-compose.fields.signature.label'))
                        ->placeholder(__('filament/concerns/email-compose.fields.signature.placeholder'))
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
                ->label(__('filament/concerns/email-compose.fields.from.label'))
                ->options(fn (): array => $this->activeAccountOptions())
                ->required(),

            TagsInput::make('to')
                ->label(__('filament/concerns/email-compose.fields.to.label'))
                ->placeholder(__('filament/concerns/email-compose.fields.to.placeholder'))
                ->required()
                ->splitKeys(['Tab', ',', ' '])
                ->suggestions(fn (): array => $this->contactEmailSuggestions()),

            Grid::make(2)
                ->schema([
                    TagsInput::make('cc')
                        ->label(__('filament/concerns/email-compose.fields.cc.label'))
                        ->placeholder(__('filament/concerns/email-compose.fields.cc.placeholder'))
                        ->splitKeys(['Tab', ',', ' '])
                        ->suggestions(fn (): array => $this->contactEmailSuggestions()),

                    TagsInput::make('bcc')
                        ->label(__('filament/concerns/email-compose.fields.bcc.label'))
                        ->placeholder(__('filament/concerns/email-compose.fields.bcc.placeholder'))
                        ->splitKeys(['Tab', ',', ' '])
                        ->suggestions(fn (): array => $this->contactEmailSuggestions()),
                ]),

            TextInput::make('subject')
                ->required()
                ->maxLength(255),

            RichEditor::make('body_html')
                ->label(__('filament/concerns/email-compose.fields.message.label'))
                ->required()
                ->mergeTags(EmailTemplateRenderService::MERGE_TAGS)
                ->customBlocks([SignatureBlock::class])
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
                        ->label(__('filament/concerns/email-compose.fields.privacy_tier.label'))
                        ->helperText(__('filament/concerns/email-compose.fields.privacy_tier.helper_text'))
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
     *     scheduled_for: \DateTimeInterface|null,
     *     priority: EmailPriority,
     * }
     */
    private function buildSendData(array $data, EmailCreationSource $source): array
    {
        $scheduledFor = null;
        if (isset($data['scheduled_for']) && $data['scheduled_for'] !== '') {
            $scheduledFor = Date::parse((string) $data['scheduled_for']);
        }

        $renderer = resolve(EmailTemplateRenderService::class);
        $record = $this->getCrmRecord();

        return [
            'connected_account_id' => $data['connected_account_id'],
            'subject' => $renderer->renderContent((string) $data['subject'], $record),
            'body_html' => $renderer->renderForSending((string) $data['body_html'], $record),
            'to' => array_map(fn (string $email): array => ['email' => $email, 'name' => null], $data['to'] ?? []),
            'cc' => array_map(fn (string $email): array => ['email' => $email, 'name' => null], $data['cc'] ?? []),
            'bcc' => array_map(fn (string $email): array => ['email' => $email, 'name' => null], $data['bcc'] ?? []),
            'in_reply_to_email_id' => $data['in_reply_to_email_id'] ?? null,
            'creation_source' => $source,
            'privacy_tier' => $this->resolvePrivacyTier($data['privacy_tier'] ?? null),
            'batch_id' => null,
            'scheduled_for' => $scheduledFor,
            'priority' => EmailPriority::PRIORITY,
            'attachments' => $data['attachments'] ?? [],
            'attachment_file_names' => $data['attachment_file_names'] ?? [],
        ];
    }

    private function defaultPrivacyTier(): EmailPrivacyTier
    {
        return resolve(PrivacyService::class)->defaultTierForUser($this->getAuthenticatedUser());
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
                ->where('user_id', $this->getAuthenticatedUser()->getKey())
                ->where('team_id', filament()->getTenant()?->getKey())
                ->where('status', 'active')
                ->defaultFirst()
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
     * @return array<string, string>
     */
    private function activeAccountOptions(): array
    {
        return ConnectedAccount::query()
            ->where('user_id', $this->getAuthenticatedUser()->getKey())
            ->where('team_id', filament()->getTenant()?->getKey())
            ->where('status', 'active')
            ->defaultFirst()
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

    /**
     * Resolve an email for reply/forward, scoped to the active team and gated by the
     * `view` policy. Returns null when the email is outside the viewer's team or hidden.
     */
    private function resolveComposableEmail(?string $emailId): ?Email
    {
        if ($emailId === null) {
            return null;
        }

        $user = $this->getAuthenticatedUser();

        /** @var Email|null $email */
        $email = Email::query()
            ->forTeam($user->current_team_id)
            ->with(['participants', 'body'])
            ->whereKey($emailId)
            ->first();

        if ($email === null) {
            return null;
        }

        if (! $user->can('view', $email)) {
            return null;
        }

        return $email;
    }

    private function getAuthenticatedUser(): User
    {
        /** @var User */
        return auth()->user();
    }
}
