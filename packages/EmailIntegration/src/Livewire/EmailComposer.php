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
use Relaticle\EmailIntegration\Actions\SendEmailAction;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\RichContent\SignatureBlock;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
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
     * @param  array{mode?: string, emailId?: string, to?: list<string>, draftId?: string}  $payload
     */
    #[On('composer:open')]
    public function open(array $payload = []): void
    {
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
            'bodyHtml' => ['required'],
        ]);

        $renderer = resolve(EmailTemplateRenderService::class);

        [$attachmentPaths, $attachmentNames] = $this->storeAttachments();

        resolve(SendEmailAction::class)->execute([
            'connected_account_id' => (string) $this->accountId,
            'subject' => $renderer->renderContent((string) $this->subject),
            'body_html' => $renderer->renderForSending($this->bodyHtmlValue()),
            'to' => array_map(fn (string $email): array => ['email' => $email, 'name' => null], $this->to),
            'cc' => array_map(fn (string $email): array => ['email' => $email, 'name' => null], $this->cc),
            'bcc' => array_map(fn (string $email): array => ['email' => $email, 'name' => null], $this->bcc),
            'in_reply_to_email_id' => null,
            'creation_source' => EmailCreationSource::COMPOSE,
            'privacy_tier' => EmailPrivacyTier::from((string) $this->privacyTier),
            'batch_id' => null,
            'attachments' => $attachmentPaths,
            'attachment_file_names' => $attachmentNames,
        ]);

        Notification::make()
            ->success()
            ->title(__('filament/emails/composer.notifications.queued.title'))
            ->send();

        $this->closeComposer();
        $this->dispatch('composer:sent');
    }

    public function minimize(): void
    {
        $this->isMinimized = true;
    }

    public function restore(): void
    {
        $this->isMinimized = false;
    }

    public function close(): void
    {
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
            ->whereHas('email', fn (Builder $q): Builder => $q->where('team_id', $teamId))
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
        if ($this->accountId === null) {
            return [];
        }

        return EmailSignature::query()
            ->where('connected_account_id', $this->accountId)
            ->pluck('name', 'id')
            ->all();
    }

    public function updatedSignatureId(?string $value): void
    {
        $signature = filled($value)
            ? EmailSignature::query()->whereKey($value)->first()
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

            $path = (string) $file->store('email-attachments', 'local');
            $paths[] = $path;
            $names[$path] = $file->getClientOriginalName();
        }

        return [$paths, $names];
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

    private function authUser(): User
    {
        /** @var User */
        return auth()->user();
    }
}
