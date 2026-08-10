<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Livewire;

use App\Models\User;
use Filament\Forms\Components\RichEditor;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use LogicException;
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
use Relaticle\EmailIntegration\Services\EmailTemplateRenderService;
use Relaticle\EmailIntegration\Services\PrivacyService;

final class EmailComposer extends Component implements HasSchemas
{
    use InteractsWithSchemas;
    use WithFileUploads;

    public bool $isOpen = false;

    public bool $isMinimized = false;

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

    /** @var array<int, mixed> */
    public array $attachments = [];

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
     * @param  array{mode?: string, emailId?: string, to?: list<string>}  $payload
     */
    #[On('composer:open')]
    public function open(array $payload = [], ?string $draftId = null): void
    {
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

        [$attachmentPaths, $attachmentNames] = $this->storeAttachments();

        resolve(SendEmailAction::class)->execute([
            'connected_account_id' => (string) $this->accountId,
            'subject' => $renderer->renderContent((string) $this->subject),
            'body_html' => $renderer->renderForSending($bodyHtml),
            'to' => array_map(fn (string $email): array => ['email' => $email, 'name' => null], $this->to),
            'cc' => array_map(fn (string $email): array => ['email' => $email, 'name' => null], $this->cc),
            'bcc' => array_map(fn (string $email): array => ['email' => $email, 'name' => null], $this->bcc),
            'in_reply_to_email_id' => null,
            'creation_source' => EmailCreationSource::COMPOSE,
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

    public function close(): void
    {
        $this->persistDraft();
        $this->warnIfAttachmentsWillBeDiscarded();
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

    public function bodySchema(Schema $schema): Schema
    {
        return $schema->components([
            RichEditor::make('bodyHtml')
                ->hiddenLabel()
                ->statePath('bodyHtml')
                ->mergeTags(EmailTemplateRenderService::MERGE_TAGS)
                ->customBlocks([SignatureBlock::class])
                ->toolbarButtons([])
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
        }
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

        $draft = resolve(SaveEmailDraftAction::class)->execute(
            user: $this->authUser(),
            data: [
                'connected_account_id' => $accountId,
                'subject' => $this->subject,
                'body_html' => $this->bodyHtmlValue(),
                'to' => $this->to,
                'cc' => $this->cc,
                'bcc' => $this->bcc,
            ],
            draftId: $this->draftId,
        );

        $this->draftId = (string) $draft->getKey();
    }

    private function isDraftEmpty(): bool
    {
        return blank($this->subject)
            && trim(strip_tags($this->bodyHtmlValue())) === ''
            && $this->to === []
            && $this->cc === []
            && $this->bcc === [];
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
            ->with(['body', 'participants'])
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
    }

    private function warnIfAttachmentsWillBeDiscarded(): void
    {
        if ($this->attachments === []) {
            return;
        }

        // Attachment persistence for drafts is explicitly out of v1 scope — the
        // fix here is only to stop discarding pending uploads silently.
        Notification::make()
            ->warning()
            ->title(__('filament/emails/composer.notifications.attachments_not_saved.title'))
            ->body(__('filament/emails/composer.notifications.attachments_not_saved.body'))
            ->send();
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
        $this->reset(['draftId', 'to', 'cc', 'bcc', 'showCc', 'showBcc', 'subject', 'bodyHtml', 'signatureId', 'attachments']);
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
        return ConnectedAccount::query()
            ->where('user_id', $this->authUser()->getKey())
            ->where('team_id', $this->authUser()->current_team_id)
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->oldest()
            ->get();
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
