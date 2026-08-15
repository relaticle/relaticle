<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Livewire;

use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\People;
use App\Models\User;
use App\Services\AvatarService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\ToolbarButtonGroup;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use LogicException;
use Relaticle\EmailIntegration\Actions\CreateEmailTemplateAction;
use Relaticle\EmailIntegration\Actions\CreateSignatureAction;
use Relaticle\EmailIntegration\Actions\DeleteDraftAttachmentAction;
use Relaticle\EmailIntegration\Actions\DeleteEmailDraftAction;
use Relaticle\EmailIntegration\Actions\SaveEmailDraftAction;
use Relaticle\EmailIntegration\Actions\SendEmailAction;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailPriority;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\RichContent\SignatureBlock;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\EmailSignature;
use Relaticle\EmailIntegration\Models\EmailTemplate;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;
use Relaticle\EmailIntegration\Services\EmailTemplateRenderService;
use Relaticle\EmailIntegration\Services\PrivacyService;

/**
 * @property-read Action $createSignatureAction
 * @property-read Action $createTemplateAction
 */
final class EmailComposer extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use WithFileUploads;

    /**
     * Per-file cap, matching the Filament compose modal this composer replaced
     * (`FileUpload::maxSize(10240)`).
     */
    private const int MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;

    /**
     * Whole-message cap. Attachment bytes are base64-encoded into the outbound
     * message (~33% overhead), and Gmail rejects the message past ~25 MB encoded
     * — by which point the email is already queued and only fails at send time.
     */
    private const int MAX_ATTACHMENTS_TOTAL_BYTES = 15 * 1024 * 1024;

    /**
     * Where this instance renders. `floating` is the bottom-right window that
     * handles Compose; `inline` is the copy docked under a message being read,
     * which handles replies and forwards only. Both are the same component, so
     * the toolbar, attachments, drafts, templates and signatures behave
     * identically on either surface — the dock only decides chrome and which
     * open event the instance answers.
     */
    #[Locked]
    public string $dock = 'floating';

    public bool $isOpen = false;

    public bool $isMinimized = false;

    public bool $isExpanded = false;

    /**
     * The message this draft was made from — replied to OR forwarded. Drives what is
     * shown above the draft and survives a save, so reopening resumes the same task.
     */
    public ?string $sourceEmailId = null;

    /**
     * Set only when the draft answers a message, so the outgoing email threads against
     * it. A forward has a source but must not thread, which is why this is separate
     * from {@see $sourceEmailId}.
     */
    public ?string $inReplyToEmailId = null;

    public ?string $replyMode = null;

    /**
     * The message being replied to, quoted into the body at send time. It is
     * never rendered in the composer — on the inline dock the original is on
     * screen directly above it.
     */
    public ?string $quotedBodyHtml = null;

    public ?string $draftId = null;

    public ?string $accountId = null;

    /** @var list<string> */
    public array $to = [];

    /** @var list<string> */
    public array $cc = [];

    /** @var list<string> */
    public array $bcc = [];

    public bool $showCc = false;

    public bool $showBcc = false;

    public ?string $subject = null;

    /**
     * Raw storage for the `bodyHtml` RichEditor field. Filament's RichEditor keeps
     * its bound Livewire property as the internal Tiptap document (an array), not
     * an HTML string — the HTML string only exists at the field's dehydrated
     * boundary (see {@see self::bodyHtmlValue()} / {@see self::setBodyHtml()}).
     * Do not read/write this property directly.
     */
    public mixed $bodyHtml = null;

    public ?string $signatureId = null;

    public ?string $privacyTier = null;

    /**
     * Pending uploads: `TemporaryUploadedFile`s not yet written to the attachment
     * disk. They become {@see $savedAttachments} the moment the draft is saved.
     *
     * @var array<int, mixed>
     */
    public array $attachments = [];

    /**
     * Attachments already persisted against the open draft, as
     * `{id, filename, size}` — the chip row renders these alongside the pending
     * uploads, which expose the same three facts through a different API.
     *
     * @var list<array{id: string, filename: string, size: int}>
     */
    public array $savedAttachments = [];

    /**
     * `$draftId` is its own parameter, not a `$payload` key. Livewire resolves
     * `#[On]` listener arguments by matching each incoming event parameter to
     * a method parameter BY NAME (`Livewire\ImplicitlyBoundMethod`, layered on
     * Laravel's container method-call binding), and this applies uniformly
     * whether the event came from a PHP-side `$this->dispatch('composer:open',
     * draftId: $id)` / the test helper, or a JS-side `$wire.dispatch('composer:open',
     * { draftId: id })` — the browser CustomEvent's `detail` object is decoded
     * into the exact same string-keyed shape server-side, it is NOT positional.
     * A same-named `$payload['draftId']` entry is never populated by either
     * caller; only a literal `draftId` parameter is.
     *
     * @param  array{to?: list<string>}  $payload
     */
    #[On('composer:open')]
    public function open(array $payload = [], ?string $draftId = null): void
    {
        // Compose belongs to the floating window. Both instances hear the event.
        if ($this->dock !== 'floating') {
            return;
        }

        // A second `composer:open` while a draft is already in progress (e.g. the `c`
        // shortcut firing after a click landed on a button, not an input) must not
        // wipe what the user has typed — just bring the composer back into view.
        if ($this->isOpen) {
            $this->isMinimized = false;

            return;
        }

        $account = $this->activeAccounts()->first();

        if ($account === null) {
            return;
        }

        $this->resetComposerState();

        $this->accountId = (string) $account->getKey();
        $this->to = $payload['to'] ?? [];
        $this->privacyTier = resolve(PrivacyService::class)
            ->defaultTierForUser($this->authUser())->value;

        $signature = $this->defaultSignatureFor($this->accountId);
        $this->signatureId = $signature?->getKey();
        $this->setBodyHtml(resolve(EmailTemplateRenderService::class)
            ->applySignatureBlock('<p></p>', $signature));

        if ($draftId !== null) {
            $this->loadDraft($draftId);
        }

        $this->isOpen = true;
        $this->isMinimized = false;
        // Composing and opening a saved draft are the same task and now present the
        // same way — fit to the screen, like the reader. A forwarded message that was
        // parked as a draft used to come back in the small corner window while the
        // message it answers opened full size, which read as two different features.
        $this->isExpanded = true;
    }

    /**
     * Open the docked composer as a reply, reply-all or forward of `$emailId`.
     * Only the inline instance answers — the floating window stays free for a
     * separate compose draft.
     */
    #[On('composer:reply')]
    public function openReply(string $emailId, string $mode = 'reply'): void
    {
        if ($this->dock !== 'inline') {
            return;
        }

        $email = $this->replyableEmail($emailId);

        if (! $email instanceof Email) {
            return;
        }

        $account = $this->activeAccounts()->first();

        if ($account === null) {
            return;
        }

        $this->resetComposerState();

        $user = $this->authUser();
        $this->accountId = (string) $account->getKey();
        $this->privacyTier = resolve(PrivacyService::class)->defaultTierForUser($user)->value;

        $this->replyMode = in_array($mode, ['reply', 'reply_all', 'forward'], true) ? $mode : 'reply';

        $this->to = match ($this->replyMode) {
            'forward' => [],
            // Reply-all addresses the original sender PLUS the to/cc recipients
            // (never bcc), minus the user's own account address.
            'reply_all' => $email->replyAllRecipients($account->email_address),
            // `where()` on the loaded collection keeps the original keys, so the
            // result has to be re-indexed to satisfy the list-typed property.
            default => array_values($email->participants
                ->where('role', EmailParticipantRole::FROM)
                ->map(fn (EmailParticipant $participant): string => (string) $participant->email_address)
                ->filter(fn (string $address): bool => $address !== '')
                ->all()),
        };

        $this->subject = ($this->replyMode === 'forward' ? 'Fwd: ' : 'Re: ').($email->subject ?? '');

        // Only quote the original body when the viewer is entitled to read it.
        $this->quotedBodyHtml = $user->can('viewBody', $email) ? $email->body?->body_html : null;
        $this->sourceEmailId = (string) $email->getKey();
        // A forward carries its source for display, but must not thread against it.
        $this->inReplyToEmailId = $this->replyMode === 'forward' ? null : $this->sourceEmailId;

        $signature = $this->defaultSignatureFor($this->accountId);
        $this->signatureId = $signature?->getKey();
        $this->setBodyHtml(resolve(EmailTemplateRenderService::class)
            ->applySignatureBlock('<p></p>', $signature));

        $this->isOpen = true;
        $this->isMinimized = false;
    }

    /**
     * Close the docked composer because the reader moved to a different message —
     * the draft answers the email that was on screen, so it must not stay attached
     * under a different one. Anything typed is saved as a draft on the way out.
     */
    #[On('composer:dismiss-inline')]
    public function dismissInline(): void
    {
        if ($this->dock !== 'inline' || ! $this->isOpen) {
            return;
        }

        $this->close();
    }

    /**
     * Reopen an unfinished reply or forward when the message it belongs to is opened.
     * A draft written here and left behind should be waiting under that message, not
     * only findable by hunting through the Drafts tab.
     */
    #[On('composer:resume-draft')]
    public function resumeDraftFor(string $emailId): void
    {
        if ($this->dock !== 'inline' || $this->isOpen) {
            return;
        }

        $original = $this->replyableEmail($emailId);

        if (! $original instanceof Email || blank($original->rfc_message_id)) {
            return;
        }

        $user = $this->authUser();

        $draft = Email::query()
            ->where('user_id', $user->getKey())
            ->where('team_id', $user->current_team_id)
            ->where('status', EmailStatus::DRAFT)
            ->where('in_reply_to', $original->rfc_message_id)
            ->latest('updated_at')
            ->first();

        $account = $this->activeAccounts()->first();

        if (! $draft instanceof Email || $account === null) {
            return;
        }

        $this->resetComposerState();

        $this->accountId = (string) $account->getKey();
        $this->privacyTier = resolve(PrivacyService::class)->defaultTierForUser($user)->value;

        $this->loadDraft((string) $draft->getKey());

        $this->isOpen = true;
        $this->isMinimized = false;

        // Nothing was clicked here — the draft came back on its own — so the reader
        // has to be told to scroll down to it, the way the reply buttons do.
        $this->dispatch('composer:opened-inline');
    }

    /**
     * The message this draft answers or forwards, for display above it. Only the
     * fitted window shows it — the inline dock already sits under the real thing.
     */
    #[Computed]
    public function sourceEmail(): ?Email
    {
        if ($this->sourceEmailId === null || $this->dock !== 'floating') {
            return null;
        }

        return $this->replyableEmail($this->sourceEmailId);
    }

    /**
     * The email a reply may target: visible to this user, in the current tenant.
     */
    private function replyableEmail(string $emailId): ?Email
    {
        $user = $this->authUser();

        $email = Email::query()
            ->with(['participants', 'body', 'shares'])
            ->forTeam($user->current_team_id)
            ->withGlobalScope('visible', new VisibleEmailScope($user))
            ->whereKey($emailId)
            ->first();

        return $email instanceof Email && $user->can('view', $email) ? $email : null;
    }

    public function send(): void
    {
        $this->validate([
            'accountId' => ['required'],
            'to' => ['required', 'array', 'min:1'],
            'to.*' => ['email'],
            'cc.*' => ['email'],
            'bcc.*' => ['email'],
            'subject' => ['required', 'string', 'max:255'],
        ]);

        $bodyHtml = $this->bodyHtmlValue();

        // `bodyHtml`'s raw state is never truly "empty" (an untouched RichEditor still
        // holds a structural `<p></p>` doc), so `required` can never catch a blank
        // message — check the dehydrated text instead. A signature-only email (no
        // free text, just the signature block) is legitimate and must still send.
        if (trim(strip_tags($bodyHtml)) === '' && ! str_contains($bodyHtml, 'data-id="'.SignatureBlock::ID.'"')) {
            $this->addError('bodyHtml', __('filament/emails/composer.validation.body_required'));

            return;
        }

        $renderer = resolve(EmailTemplateRenderService::class);

        [$pendingPaths, $pendingNames] = $this->storeAttachments();
        [$copiedPaths, $copiedNames] = $this->copySavedAttachments();

        $attachmentPaths = [...$pendingPaths, ...$copiedPaths];
        $attachmentNames = [...$pendingNames, ...$copiedNames];

        resolve(SendEmailAction::class)->execute([
            'connected_account_id' => (string) $this->accountId,
            'subject' => $renderer->renderContent((string) $this->subject),
            'body_html' => $renderer->renderForSending($this->withQuotedBody($bodyHtml)),
            'to' => array_map(fn (string $email): array => ['email' => $email, 'name' => null], $this->to),
            'cc' => array_map(fn (string $email): array => ['email' => $email, 'name' => null], $this->cc),
            'bcc' => array_map(fn (string $email): array => ['email' => $email, 'name' => null], $this->bcc),
            'in_reply_to_email_id' => $this->inReplyToEmailId,
            'creation_source' => $this->creationSource(),
            'privacy_tier' => EmailPrivacyTier::from((string) $this->privacyTier),
            'batch_id' => null,
            // Interactive sends from the composer keep the undo-send window (matches
            // the surface being replaced — HasEmailComposeActions::buildSendData()).
            'priority' => EmailPriority::PRIORITY,
            'attachments' => $attachmentPaths,
            'attachment_file_names' => $attachmentNames,
        ]);

        if ($this->draftId !== null) {
            // Best-effort: two tabs open on the same draft, or a retried request,
            // can mean the draft row is already gone by now. SendEmailAction has
            // already committed the queued email above — a 403 here must never
            // abort this method (it would leave the composer open and populated
            // with no feedback, inviting the user to press Send again and queue
            // a duplicate). executeIfExists() is a no-op when the draft is gone.
            resolve(DeleteEmailDraftAction::class)->executeIfExists($this->authUser(), $this->draftId);
        }

        Notification::make()
            ->success()
            ->title(__('filament/emails/composer.notifications.queued.title'))
            ->send();

        $this->closeComposer();
        $this->dispatch('composer:sent');
        // A send both removes the draft (if any) and adds an outbox row.
        $this->dispatch('drafts:changed');
    }

    private function creationSource(): EmailCreationSource
    {
        return match ($this->replyMode) {
            'reply' => EmailCreationSource::REPLY,
            'reply_all' => EmailCreationSource::REPLY_ALL,
            'forward' => EmailCreationSource::FORWARD,
            default => EmailCreationSource::COMPOSE,
        };
    }

    /**
     * Append the original message to a reply or forward. The composer never shows
     * this — the message is on screen above the dock — but the recipient's client
     * needs it for the conversation to read as a thread.
     */
    private function withQuotedBody(string $bodyHtml): string
    {
        if (blank($this->quotedBodyHtml)) {
            return $bodyHtml;
        }

        return $bodyHtml.($this->replyMode === 'forward'
            ? '<br><p><strong>---------- Forwarded message ----------</strong></p>'.$this->quotedBodyHtml
            : '<br><blockquote style="border-left:3px solid #ccc;margin-left:0;padding-left:1rem">'.$this->quotedBodyHtml.'</blockquote>');
    }

    public function minimize(): void
    {
        $this->persistDraft();
        $this->isMinimized = true;
    }

    public function restore(): void
    {
        $this->isMinimized = false;
    }

    public function toggleExpand(): void
    {
        $this->isExpanded = ! $this->isExpanded;
        $this->isMinimized = false;
    }

    /**
     * Put the draft away and keep it. Used when the composer is dismissed by
     * something other than the user rejecting it — minimizing, or the reader moving
     * to another message — where losing what was typed would be a surprise.
     */
    public function close(): void
    {
        $this->persistDraft();
        $this->closeComposer();
    }

    /**
     * Throw the draft away, including any row a previous save already wrote. This is
     * what the × means: dismissing a draft should not quietly leave it behind in
     * Drafts. {@see close()} is the keep-it path.
     */
    public function discard(): void
    {
        if ($this->draftId !== null) {
            resolve(DeleteEmailDraftAction::class)->executeIfExists($this->authUser(), $this->draftId);

            $this->dispatch('drafts:changed');
        }

        $this->closeComposer();
    }

    public function toggleCc(): void
    {
        $this->showCc = ! $this->showCc;
    }

    public function toggleBcc(): void
    {
        $this->showBcc = ! $this->showBcc;
    }

    /**
     * Enforce the size caps as files arrive, dropping (and deleting) anything
     * over them rather than reporting an error and leaving the file in state —
     * an invalid attachment left in `$attachments` would still be stored and
     * sent by {@see self::send()}, which does not re-check.
     */
    public function updatedAttachments(): void
    {
        $kept = [];
        $rejected = [];
        $total = 0;

        foreach ($this->attachments as $file) {
            if (! $file instanceof TemporaryUploadedFile) {
                continue;
            }

            $size = $file->getSize();

            if ($size > self::MAX_ATTACHMENT_BYTES || $total + $size > self::MAX_ATTACHMENTS_TOTAL_BYTES) {
                $rejected[] = $file->getClientOriginalName();
                $file->delete();

                continue;
            }

            $total += $size;
            $kept[] = $file;
        }

        $this->attachments = $kept;

        if ($rejected === []) {
            return;
        }

        Notification::make()
            ->warning()
            ->title(__('filament/emails/composer.notifications.attachment_too_large.title'))
            ->body(__('filament/emails/composer.notifications.attachment_too_large.body', [
                'files' => implode(', ', $rejected),
                'max' => Number::fileSize(self::MAX_ATTACHMENT_BYTES),
                'total' => Number::fileSize(self::MAX_ATTACHMENTS_TOTAL_BYTES),
            ]))
            ->send();
    }

    public function removeAttachment(int $index): void
    {
        unset($this->attachments[$index]);

        $this->attachments = array_values($this->attachments);
    }

    /**
     * Detach a file the open draft has already stored. Unlike a pending upload
     * this has to reach the database and the disk, so it goes through an action
     * that re-verifies ownership.
     */
    public function removeSavedAttachment(string $attachmentId): void
    {
        if ($this->draftId === null) {
            return;
        }

        resolve(DeleteDraftAttachmentAction::class)
            ->execute($this->authUser(), $this->draftId, $attachmentId);

        $this->savedAttachments = array_values(array_filter(
            $this->savedAttachments,
            fn (array $attachment): bool => $attachment['id'] !== $attachmentId,
        ));

        $this->dispatch('drafts:changed');
    }

    public function bodySchema(Schema $schema): Schema
    {
        return $schema->components([
            RichEditor::make('bodyHtml')
                ->hiddenLabel()
                ->resizableImages()
                ->statePath('bodyHtml')
                ->mergeTags(EmailTemplateRenderService::MERGE_TAGS)
                ->customBlocks([SignatureBlock::class])
                ->placeholder(__('filament/emails/composer.fields.body_placeholder'))
                ->toolbarButtons([
                    ['bold', 'italic', 'underline', 'strike', 'attachFiles'],
                    [ToolbarButtonGroup::make(__('filament/emails/composer.toolbar.paragraph'), ['paragraph', 'h1', 'h2', 'h3'])],
                    [ToolbarButtonGroup::make(__('filament/emails/composer.toolbar.alignment'), ['alignStart', 'alignCenter', 'alignEnd', 'alignJustify'])],
                    ['blockquote', 'codeBlock', 'bulletList', 'orderedList'],
                    ['undo', 'redo'],
                ])
                ->floatingToolbars([
                    'paragraph' => ['bold', 'italic', 'underline', 'strike', 'link', 'bulletList', 'orderedList', 'blockquote'],
                ])
                ->extraAttributes(['class' => 'email-composer-body']),
        ]);
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function recipientSuggestions(): array
    {
        $teamId = $this->authUser()->current_team_id;

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
            ->all();
    }

    /**
     * @return list<array{
     *     type: 'person'|'company_team',
     *     id: string,
     *     label: string,
     *     description: string|null,
     *     email?: string,
     *     count?: int,
     *     emails?: list<string>,
     * }>
     */
    #[Computed]
    public function recipientOptions(): array
    {
        $teamId = (string) $this->authUser()->current_team_id;
        $people = People::query()
            ->where('team_id', $teamId)
            ->orderBy('name')
            ->limit(300)
            ->get(['id', 'name', 'company_id']);

        $peopleIds = [];

        foreach ($people as $person) {
            $peopleIds[] = (string) $person->getKey();
        }

        $primaryEmailEntries = $this->primaryEmailEntriesForPeople($peopleIds, $teamId);
        $options = [];

        foreach ($people as $person) {
            $personId = (string) $person->getKey();
            $email = $this->primaryEmailForPersonId($primaryEmailEntries, $personId);

            if ($email === null) {
                continue;
            }

            $options[] = [
                'type' => 'person',
                'id' => $personId,
                'label' => $person->name,
                'description' => $email,
                'email' => $email,
            ];
        }

        foreach ($this->companyTeamRecipientOptions($teamId) as $companyOption) {
            $options[] = $companyOption;
        }

        return $options;
    }

    public function addCompanyTeamRecipients(string $companyId, string $field = 'to'): void
    {
        if (! in_array($field, ['to', 'cc', 'bcc'], true)) {
            return;
        }

        $teamId = (string) $this->authUser()->current_team_id;
        $people = People::query()
            ->where('team_id', $teamId)
            ->where('company_id', $companyId)
            ->get(['id']);

        $peopleIds = [];

        foreach ($people as $person) {
            $peopleIds[] = (string) $person->getKey();
        }

        $emails = [];

        foreach ($this->primaryEmailEntriesForPeople($peopleIds, $teamId) as $entry) {
            $emails[] = $entry['email'];
        }
        $nextRecipients = match ($field) {
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            default => $this->to,
        };

        foreach ($emails as $email) {
            if (! in_array($email, $nextRecipients, true)) {
                $nextRecipients[] = $email;
            }
        }

        if ($field === 'cc') {
            $this->cc = $nextRecipients;

            return;
        }

        if ($field === 'bcc') {
            $this->bcc = $nextRecipients;

            return;
        }

        $this->to = $nextRecipients;
    }

    /**
     * @return list<array{
     *     type: 'company_team',
     *     id: string,
     *     label: string,
     *     description: string,
     *     count: int,
     *     emails: list<string>,
     * }>
     */
    private function companyTeamRecipientOptions(string $teamId): array
    {
        $people = People::query()
            ->where('team_id', $teamId)
            ->whereNotNull('company_id')
            ->orderBy('name')
            ->get(['id', 'name', 'company_id']);

        $peopleIds = [];

        foreach ($people as $person) {
            $peopleIds[] = (string) $person->getKey();
        }

        $primaryEmailEntries = $this->primaryEmailEntriesForPeople($peopleIds, $teamId);
        $companyCounts = [];
        $companyEmails = [];

        foreach ($people as $person) {
            $email = $this->primaryEmailForPersonId($primaryEmailEntries, (string) $person->getKey());

            if ($email === null) {
                continue;
            }

            if ($person->company_id === null) {
                continue;
            }

            $companyId = (string) $person->company_id;
            $companyCounts[$companyId] = ($companyCounts[$companyId] ?? 0) + 1;
            $companyEmails[$companyId][] = $email;
        }

        if ($companyCounts === []) {
            return [];
        }

        /** @var list<array{type: 'company_team', id: string, label: string, description: string, count: int, emails: list<string>}> */
        return Company::query()
            ->where('team_id', $teamId)
            ->whereKey(array_keys($companyCounts))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function (Company $company) use ($companyCounts, $companyEmails): array {
                $companyId = (string) $company->getKey();

                return [
                    'type' => 'company_team',
                    'id' => $companyId,
                    'label' => $company->name,
                    'description' => __('filament/emails/composer.fields.company_team'),
                    'count' => $companyCounts[$companyId],
                    'emails' => $companyEmails[$companyId],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function accountOptions(): array
    {
        return $this->activeAccounts()
            ->mapWithKeys(fn (ConnectedAccount $account): array => [(string) $account->getKey() => $account->label])
            ->all();
    }

    /**
     * The account the email will actually be sent from, for the "From" row.
     */
    #[Computed]
    public function fromAccount(): ?ConnectedAccount
    {
        $accountId = $this->ownedAccountId();

        return $accountId === null
            ? null
            : $this->activeAccounts()->firstWhere(fn (ConnectedAccount $account): bool => (string) $account->getKey() === $accountId);
    }

    /**
     * Avatar for the "From" row, generated from the sending account's own name —
     * not the signed-in user's profile photo, which would be misleading on a
     * shared or delegated mailbox.
     */
    #[Computed]
    public function fromAvatarUrl(): ?string
    {
        $account = $this->fromAccount();

        return $account instanceof ConnectedAccount
            ? resolve(AvatarService::class)->generate($account->display_name ?? $account->email_address)
            : null;
    }

    /**
     * @param  list<string>  $peopleIds
     * @return list<array{person_id: string, email: string}>
     */
    private function primaryEmailEntriesForPeople(array $peopleIds, string $teamId): array
    {
        if ($peopleIds === []) {
            return [];
        }

        $emailField = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $teamId)
            ->where('entity_type', 'people')
            ->where('code', 'emails')
            ->first();

        if (! $emailField instanceof CustomField) {
            return [];
        }

        $entries = [];

        foreach (CustomFieldValue::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $teamId)
            ->where('entity_type', 'people')
            ->where('custom_field_id', $emailField->getKey())
            ->whereIn('entity_id', $peopleIds)
            ->get(['entity_id', 'json_value']) as $value) {
            $email = $this->primaryEmailFromValue($value->json_value);

            if ($email !== null) {
                $entries[] = [
                    'person_id' => (string) $value->entity_id,
                    'email' => $email,
                ];
            }
        }

        return $entries;
    }

    /**
     * @param  list<array{person_id: string, email: string}>  $entries
     */
    private function primaryEmailForPersonId(array $entries, string $personId): ?string
    {
        foreach ($entries as $entry) {
            if ($entry['person_id'] === $personId) {
                return $entry['email'];
            }
        }

        return null;
    }

    private function primaryEmailFromValue(mixed $value): ?string
    {
        $emails = $value instanceof Collection
            ? $value
            : collect(is_array($value) ? $value : []);

        $email = $emails
            ->filter(fn (mixed $item): bool => is_string($item) && trim($item) !== '')
            ->first();

        return is_string($email) ? $email : null;
    }

    /**
     * Team templates the user may apply: shared ones plus their own.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function templateOptions(): array
    {
        return $this->ownedTemplates()->pluck('name', 'id')->all();
    }

    /**
     * Fill subject and body from a template, keeping the current signature below
     * the template body so applying one never discards it.
     */
    public function applyTemplate(string $templateId): void
    {
        $template = $this->ownedTemplates()->whereKey($templateId)->first();

        if (! $template instanceof EmailTemplate) {
            return;
        }

        $signature = filled($this->signatureId)
            ? EmailSignature::query()
                ->where('connected_account_id', $this->ownedAccountId())
                ->whereKey($this->signatureId)
                ->first()
            : null;

        $rendered = resolve(EmailTemplateRenderService::class)->renderWithSignature($template, null, $signature);

        $this->subject = $rendered['subject'];
        $this->setBodyHtml($rendered['body_html']);
    }

    /**
     * Save what is currently in the composer as a reusable template, without
     * leaving the composer. The signature block is stripped from the prefilled
     * body: {@see self::applyTemplate()} re-applies the current signature below
     * the template body, so keeping it here would duplicate it.
     */
    public function createTemplateAction(): Action
    {
        return Action::make('createTemplate')
            ->label(__('filament/emails/composer.actions.create_template'))
            ->modalWidth('2xl')
            ->fillForm(fn (): array => [
                'name' => $this->subject,
                'subject' => $this->subject,
                'body_html' => resolve(EmailTemplateRenderService::class)->stripSignatureBlock($this->bodyHtmlValue()),
            ])
            ->schema([
                TextInput::make('name')
                    ->label(__('filament/emails/composer.fields.template_name'))
                    ->required()
                    ->maxLength(100),
                TextInput::make('subject')
                    ->label(__('filament/emails/composer.fields.subject'))
                    ->required()
                    ->maxLength(255),
                RichEditor::make('body_html')
                    ->label(__('filament/emails/composer.fields.message'))
                    ->required()
                    ->mergeTags(EmailTemplateRenderService::MERGE_TAGS)
                    ->toolbarButtons(['bold', 'italic', 'underline', 'strike', 'link', 'bulletList', 'orderedList']),
                Toggle::make('is_shared')
                    ->label(__('filament/emails/composer.fields.template_shared')),
            ])
            ->action(function (array $data, CreateEmailTemplateAction $createEmailTemplate): void {
                $createEmailTemplate->execute($this->authUser(), [
                    'name' => $data['name'],
                    'subject' => $data['subject'],
                    'body_html' => $data['body_html'],
                    'is_shared' => (bool) ($data['is_shared'] ?? false),
                ]);

                unset($this->templateOptions);

                Notification::make()
                    ->success()
                    ->title(__('filament/emails/composer.notifications.template_created.title'))
                    ->send();
            });
    }

    /**
     * Create a signature for the account currently selected in the "From" row and
     * apply it to the message immediately.
     */
    public function createSignatureAction(): Action
    {
        return Action::make('createSignature')
            ->label(__('filament/emails/composer.actions.create_signature'))
            ->modalWidth('2xl')
            ->schema([
                TextInput::make('name')
                    ->label(__('filament/emails/composer.fields.signature_name'))
                    ->required()
                    ->maxLength(100),
                RichEditor::make('content_html')
                    ->label(__('filament/emails/composer.fields.signature_content'))
                    ->required()
                    ->toolbarButtons(['bold', 'italic', 'underline', 'link']),
                Toggle::make('is_default')
                    ->label(__('filament/emails/composer.fields.signature_default')),
            ])
            ->action(function (array $data, CreateSignatureAction $createSignature): void {
                $account = $this->fromAccount();

                if (! $account instanceof ConnectedAccount) {
                    return;
                }

                $signature = $createSignature->execute($account, [
                    'name' => $data['name'],
                    'content_html' => $data['content_html'],
                    'is_default' => (bool) ($data['is_default'] ?? false),
                ]);

                unset($this->signatureOptions);

                $this->signatureId = (string) $signature->getKey();
                $this->updatedSignatureId($this->signatureId);

                Notification::make()
                    ->success()
                    ->title(__('filament/emails/composer.notifications.signature_created.title'))
                    ->send();
            });
    }

    /**
     * @return EloquentBuilder<EmailTemplate>
     */
    private function ownedTemplates(): EloquentBuilder
    {
        return EmailTemplate::query()
            ->where('team_id', $this->authUser()->current_team_id)
            ->where(fn (Builder $query): Builder => $query
                ->where('is_shared', true)
                ->orWhere('created_by', $this->authUser()->getKey()));
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function signatureOptions(): array
    {
        $accountId = $this->ownedAccountId();

        if ($accountId === null) {
            return [];
        }

        return EmailSignature::query()
            ->where('connected_account_id', $accountId)
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * `accountId` is a plain public Livewire property, so a client can post any
     * ULID. Reject anything that isn't one of this user's own active accounts so
     * every downstream read (signature options, the default signature, `send()`)
     * inherits ownership instead of re-deriving it — see {@see self::ownedAccountId()}.
     */
    public function updatedAccountId(?string $value): void
    {
        if ($value !== null && ! $this->isOwnedAccountId($value)) {
            $this->accountId = null;

            return;
        }

        $signature = $this->defaultSignatureFor($value);
        $this->signatureId = $signature?->getKey();
        $this->updatedSignatureId($this->signatureId);
    }

    public function updatedSignatureId(?string $value): void
    {
        $accountId = $this->ownedAccountId();

        $signature = (filled($value) && $accountId !== null)
            ? EmailSignature::query()
                ->where('connected_account_id', $accountId)
                ->whereKey($value)
                ->first()
            : null;

        $body = $this->bodyHtmlValue();
        $this->setBodyHtml(resolve(EmailTemplateRenderService::class)
            ->applySignatureBlock($body !== '' ? $body : '<p></p>', $signature));
    }

    public function render(): View
    {
        return view('livewire.email-composer');
    }

    /**
     * Write an HTML string into the RichEditor field's bound state, applying the
     * field's own state cast (HTML -> internal Tiptap document) so the raw
     * `bodyHtml` property stays in the format the editor expects.
     */
    private function setBodyHtml(string $html): void
    {
        $this->bodyField()->state($html);
    }

    /**
     * Read the RichEditor field's current value dehydrated back to HTML, applying
     * the field's own state cast (internal Tiptap document -> HTML).
     */
    private function bodyHtmlValue(): string
    {
        $state = $this->bodyField()->getState();

        return is_string($state) ? $state : '';
    }

    private function bodyField(): RichEditor
    {
        $component = $this->getSchema('bodySchema')?->getComponent('bodyHtml');

        throw_unless($component instanceof RichEditor, LogicException::class, 'The email composer body field is not registered on the bodySchema.');

        return $component;
    }

    /**
     * Persist pending uploads to the same disk/directory the old Filament
     * FileUpload used, in the shape SendEmailAction consumes.
     *
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private function storeAttachments(): array
    {
        $paths = [];
        $names = [];

        foreach ($this->attachments as $file) {
            if (! $file instanceof TemporaryUploadedFile) {
                continue;
            }

            $path = (string) $file->store('email-attachments', EmailAttachment::DISK);
            $paths[] = $path;
            $names[$path] = $file->getClientOriginalName();
        }

        return [$paths, $names];
    }

    /**
     * Duplicate the open draft's stored attachments for the outgoing email. The
     * copy is deliberate: the queued email and the draft must not share bytes,
     * or deleting the draft right after send (see {@see self::send()}) would pull
     * the files out from under a message that has not gone out yet.
     *
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private function copySavedAttachments(): array
    {
        if ($this->draftId === null || $this->savedAttachments === []) {
            return [[], []];
        }

        $disk = Storage::disk(EmailAttachment::DISK);
        $paths = [];
        $names = [];

        $attachments = EmailAttachment::query()
            ->where('email_id', $this->draftId)
            ->whereIn('id', array_column($this->savedAttachments, 'id'))
            ->whereHas('email', fn (Builder $query): Builder => $query
                ->where('user_id', $this->authUser()->getKey())
                ->where('team_id', $this->authUser()->current_team_id)
                ->where('status', EmailStatus::DRAFT))
            ->get();

        foreach ($attachments as $attachment) {
            $source = $attachment->storage_path;
            if ($source === null) {
                continue;
            }
            if (! $disk->exists($source)) {
                continue;
            }

            $copy = 'email-attachments/'.Str::ulid().'.'.pathinfo($source, PATHINFO_EXTENSION);

            $disk->copy($source, $copy);

            $paths[] = $copy;
            $names[$copy] = (string) $attachment->filename;
        }

        return [$paths, $names];
    }

    /**
     * Persist the in-progress message as a DRAFT unless it is blank. Skipped
     * entirely for a blank compose (nothing to save) or when the account was
     * rejected by {@see self::ownedAccountId()} (nothing safe to save under).
     * Reusing this on both `minimize()` and `close()` means a user who clears
     * out an already-saved draft and closes leaves that draft row untouched
     * rather than wiping it — {@see SaveEmailDraftAction} never runs in that
     * case, so nothing to reconcile.
     */
    private function persistDraft(): void
    {
        if ($this->isDraftEmpty()) {
            return;
        }

        $accountId = $this->ownedAccountId();

        if ($accountId === null) {
            return;
        }

        [$attachmentPaths, $attachmentNames] = $this->storeAttachments();

        $draft = resolve(SaveEmailDraftAction::class)->execute(
            user: $this->authUser(),
            data: [
                'connected_account_id' => $accountId,
                'subject' => $this->subject,
                'body_html' => $this->bodyHtmlValue(),
                'to' => $this->to,
                'cc' => $this->cc,
                'bcc' => $this->bcc,
                // Without these a reply parked as a draft comes back as a plain new
                // message: no original to show, and no threading when it is sent.
                'source_email_id' => $this->sourceEmailId,
                'creation_source' => $this->creationSource(),
                'attachments' => $attachmentPaths,
                'attachment_file_names' => $attachmentNames,
            ],
            draftId: $this->draftId,
        );

        $this->draftId = (string) $draft->getKey();

        // The pending uploads are rows on the draft now; keeping them in
        // `$attachments` too would store and attach them a second time on the
        // next save, and send them twice.
        $this->attachments = [];
        $this->loadSavedAttachments($draft);

        // The drafts tab and its badge are rendered by other components; without
        // this they only pick the new draft up on a full page load.
        $this->dispatch('drafts:changed');
    }

    private function isDraftEmpty(): bool
    {
        return blank($this->subject)
            && trim(strip_tags($this->bodyHtmlValue())) === ''
            && $this->to === []
            && $this->cc === []
            && $this->bcc === []
            && $this->attachments === []
            && $this->savedAttachments === [];
    }

    private function loadSavedAttachments(Email $draft): void
    {
        /** @var list<array{id: string, filename: string, size: int}> $rows */
        $rows = $draft->attachments()
            ->get()
            ->map(fn (EmailAttachment $attachment): array => [
                'id' => (string) $attachment->getKey(),
                'filename' => (string) $attachment->filename,
                'size' => (int) $attachment->size,
            ])
            ->values()
            ->all();

        $this->savedAttachments = $rows;
    }

    /**
     * `$draftId` arrives from the same client-controlled `composer:open` event
     * payload as any other `open()` argument (see {@see self::open()}), so it
     * must be re-verified here rather than trusted — scope the lookup to this
     * user's own DRAFT rows *within their current team* (a multi-team user has
     * one `user_id` but no cross-team access; Email has no team global scope)
     * so a foreign or cross-team id can never leak draft content into the
     * composer. A miss (foreign, cross-team, deleted, or already sent) is
     * silently ignored and the composer opens blank.
     */
    private function loadDraft(string $draftId): void
    {
        $draft = Email::query()
            ->with(['body', 'participants', 'attachments'])
            ->where('user_id', $this->authUser()->getKey())
            ->where('team_id', $this->authUser()->current_team_id)
            ->where('status', EmailStatus::DRAFT)
            ->whereKey($draftId)
            ->first();

        if ($draft === null) {
            return;
        }

        $this->draftId = (string) $draft->getKey();

        if ($this->isOwnedAccountId((string) $draft->connected_account_id)) {
            $this->accountId = (string) $draft->connected_account_id;
        } else {
            // The account this draft was composed from was disconnected since
            // it was saved. `open()` already selected a default active account
            // above — keep that rather than loading a stale, unowned account id
            // that would crash `send()` inside SendEmailAction's ownedBy()
            // lookup with an unhandled ModelNotFoundException.
            Notification::make()
                ->warning()
                ->title(__('filament/emails/composer.notifications.draft_account_disconnected.title'))
                ->body(__('filament/emails/composer.notifications.draft_account_disconnected.body'))
                ->send();
        }

        $this->subject = $draft->subject;
        $this->setBodyHtml((string) $draft->body?->body_html);

        $this->to = $this->participantAddresses($draft, EmailParticipantRole::TO);
        $this->cc = $this->participantAddresses($draft, EmailParticipantRole::CC);
        $this->bcc = $this->participantAddresses($draft, EmailParticipantRole::BCC);

        $this->showCc = $this->cc !== [];
        $this->showBcc = $this->bcc !== [];

        $this->restoreReplyContext($draft);

        $this->loadSavedAttachments($draft);
    }

    /**
     * Put a reply draft back into reply mode. The link survives as the original's RFC
     * message id on the draft row, so it is resolved back to the email here — which is
     * what lets the composer show what is being answered and thread the sent message.
     */
    private function restoreReplyContext(Email $draft): void
    {
        if (blank($draft->in_reply_to)) {
            return;
        }

        $user = $this->authUser();

        $original = Email::query()
            ->with(['body', 'participants', 'from', 'shares'])
            ->where('team_id', $user->current_team_id)
            ->where('rfc_message_id', $draft->in_reply_to)
            ->withGlobalScope('visible', new VisibleEmailScope($user))
            ->first();

        if (! $original instanceof Email || $user->cannot('view', $original)) {
            return;
        }

        $this->sourceEmailId = (string) $original->getKey();
        $this->replyMode = match ($draft->creation_source) {
            EmailCreationSource::REPLY_ALL => 'reply_all',
            EmailCreationSource::FORWARD => 'forward',
            default => 'reply',
        };
        $this->inReplyToEmailId = $this->replyMode === 'forward' ? null : $this->sourceEmailId;
        $this->quotedBodyHtml = $user->can('viewBody', $original) ? $original->body?->body_html : null;
    }

    /**
     * @return list<string>
     */
    private function participantAddresses(Email $draft, EmailParticipantRole $role): array
    {
        /** @var list<string> */
        return $draft->participants
            ->where('role', $role)
            ->pluck('email_address')
            ->values()
            ->all();
    }

    private function closeComposer(): void
    {
        $this->resetComposerState();
        $this->isOpen = false;
        $this->isMinimized = false;
    }

    private function resetComposerState(): void
    {
        $this->reset(['draftId', 'to', 'cc', 'bcc', 'showCc', 'showBcc', 'subject', 'bodyHtml', 'signatureId', 'attachments', 'savedAttachments', 'replyMode', 'sourceEmailId', 'inReplyToEmailId', 'quotedBodyHtml']);
        $this->resetErrorBag();
    }

    private function defaultSignatureFor(?string $accountId): ?EmailSignature
    {
        if (blank($accountId)) {
            return null;
        }

        return EmailSignature::query()
            ->where('connected_account_id', $accountId)
            ->where('is_default', true)
            ->first();
    }

    /**
     * @return Collection<int, ConnectedAccount>
     */
    private function activeAccounts(): Collection
    {
        return once(fn (): Collection => ConnectedAccount::query()
            ->where('user_id', $this->authUser()->getKey())
            ->where('team_id', $this->authUser()->current_team_id)
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->oldest()
            ->get());
    }

    /**
     * `$this->accountId` only ever reaches here as trusted after
     * {@see self::updatedAccountId()} has rejected anything foreign — but that hook
     * firing depends on Livewire's per-property update order, which a hand-crafted
     * payload controls. Re-verify ownership inline so every reader is safe on its
     * own, regardless of hook ordering.
     */
    private function ownedAccountId(): ?string
    {
        if ($this->accountId === null || ! $this->isOwnedAccountId($this->accountId)) {
            return null;
        }

        return $this->accountId;
    }

    private function isOwnedAccountId(string $accountId): bool
    {
        return $this->activeAccounts()
            ->contains(fn (ConnectedAccount $account): bool => (string) $account->getKey() === $accountId);
    }

    private function authUser(): User
    {
        /** @var User */
        return auth()->user();
    }
}
