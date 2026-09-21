<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use App\Filament\Pages\Concerns\HasWorkspaceSettingsNavigation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use Livewire\Attributes\Computed;
use Relaticle\EmailIntegration\Actions\CreateSignatureAction;
use Relaticle\EmailIntegration\Actions\DeleteSignatureAction;
use Relaticle\EmailIntegration\Actions\SaveUserEmailSharingDefaultAction;
use Relaticle\EmailIntegration\Actions\UpdateConnectedAccountBlocklistAction;
use Relaticle\EmailIntegration\Actions\UpdateConnectedAccountSettingsAction;
use Relaticle\EmailIntegration\Actions\UpdateSignatureAction;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Concerns\HasConnectedAccountActions;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailFeatureFlag;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailBlocklist;
use Relaticle\EmailIntegration\Models\EmailSignature;
use Relaticle\EmailIntegration\Services\PrivacyService;
use Relaticle\EmailIntegration\Support\SharingTierChangeConfirmation;

/**
 * Per-account settings, reached from the "Settings" entry of an account's action
 * group on {@see EmailAccountsPage}.
 *
 * Sharing tier is stored per user + team (not per account), so the General and
 * Sharing tabs edit settings that apply to every mailbox this user has connected.
 * Blocklist and signatures are per account.
 *
 * @property-read Schema $form
 * @property-read Collection<int, EmailBlocklist> $blocklistEntries
 */
final class EmailAccountSettingsPage extends Page implements HasSchemas
{
    use HasConnectedAccountActions, HasEmailFeatureFlag, HasWorkspaceSettingsNavigation, InteractsWithSchemas;

    protected string $view = 'email-integration::filament.pages.email-account-settings';

    protected static ?string $slug = 'workspace/email/accounts/{account}';

    protected static bool $shouldRegisterNavigation = false;

    protected ?string $heading = '';

    protected ?string $subheading = null;

    public string $accountId;

    private ?ConnectedAccount $account = null;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var Collection<int, EmailSignature> */
    public Collection $signatures;

    public function mount(string $account): void
    {
        $this->accountId = $this->ownedAccountsQuery()->findOrFail($account)->getKey();

        /** @var User $user */
        $user = auth()->user();

        $this->form->fill([
            'sync_inbox' => $this->account()->sync_inbox,
            'sync_sent' => $this->account()->sync_sent,
            'hourly_send_limit' => $this->account()->hourly_send_limit,
            'daily_send_limit' => $this->account()->daily_send_limit,
            'default_email_sharing_tier' => $user->default_email_sharing_tier->value ?? '',
        ]);

        $this->signatures = $this->loadSignatures();
    }

    /**
     * @return Collection<int, EmailBlocklist>
     */
    #[Computed]
    public function blocklistEntries(): Collection
    {
        return $this->loadBlocklistEntries();
    }

    public function getTitle(): string
    {
        return $this->account()->email_address;
    }

    /**
     * @return array<int|string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [
            EmailAccountsPage::getUrl() => (string) __('workspaces.tabs.email'),
            $this->account()->email_address,
        ];
    }

    public function account(): ConnectedAccount
    {
        return $this->account ??= $this->ownedAccountsQuery()->findOrFail($this->accountId);
    }

    public function refreshAccount(): void
    {
        $this->account = null;
    }

    public function isImportingHistory(): bool
    {
        return $this->account()->isImportingHistory();
    }

    public function shouldPollAccountStatus(): bool
    {
        return $this->account()->showsSyncProgress();
    }

    protected function afterAccountChanged(): void
    {
        $this->account = null;
    }

    protected function afterAccountDisconnected(): void
    {
        $this->redirect(EmailAccountsPage::getUrl());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Tabs::make()
                    ->tabs([
                        Tab::make(__('filament/pages/email-account-settings.tabs.general'))
                            ->icon(Heroicon::OutlinedCog6Tooth)
                            ->schema($this->generalTabSchema()),

                        Tab::make(__('filament/pages/email-account-settings.tabs.sharing'))
                            ->icon(Heroicon::OutlinedShieldCheck)
                            ->schema([$this->sharingTierField()]),

                        Tab::make(__('filament/pages/email-account-settings.tabs.blocklist'))
                            ->icon(Heroicon::OutlinedNoSymbol)
                            ->schema([
                                Section::make(__('filament/pages/email-account-settings.blocklist.label'))
                                    ->description(__('filament/pages/email-account-settings.blocklist.hint'))
                                    ->compact()
                                    ->headerActions([
                                        fn (): Action => $this->addBlocklistAction()
                                            ->visible(fn (): bool => $this->blocklistEntries->isNotEmpty()),
                                    ])
                                    ->schema([$this->blocklistField()]),
                            ]),

                        Tab::make(__('filament/pages/email-account-settings.tabs.signatures'))
                            ->icon(Heroicon::OutlinedPencilSquare)
                            ->schema([
                                Section::make(__('filament/pages/email-account-settings.signatures.label'))
                                    ->description(__('filament/pages/email-account-settings.signatures.hint'))
                                    ->compact()
                                    ->headerActions([
                                        fn (): Action => $this->createSignatureAction()
                                            ->visible(fn (): bool => $this->signatures->isNotEmpty()),
                                    ])
                                    ->schema([$this->signaturesField()]),
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * @return array<int, Component|Field>
     */
    private function generalTabSchema(): array
    {
        // One setting per row, label on the left and control on the right: a settings
        // list reads calmer than a grid of mixed control types.
        return [
            Toggle::make('sync_inbox')
                ->label($this->labelWithInfo(__('filament/pages/email-accounts.settings.sync_inbox.label'), __('filament/pages/email-accounts.settings.sync_inbox.helper_text')))
                ->inlineLabel(),

            Toggle::make('sync_sent')
                ->label($this->labelWithInfo(__('filament/pages/email-accounts.settings.sync_sent.label'), __('filament/pages/email-accounts.settings.sync_sent.helper_text')))
                ->inlineLabel(),

            Grid::make(2)
                ->schema([
                    TextInput::make('hourly_send_limit')
                        ->label($this->labelWithInfo(
                            __('filament/pages/email-accounts.settings.hourly_send_limit.label'),
                            __('filament/pages/email-accounts.settings.hourly_send_limit.helper_text'),
                        ))
                        ->numeric()
                        ->minValue(1)
                        ->placeholder(__('filament/pages/email-accounts.settings.hourly_send_limit.placeholder', ['default' => Config::integer('email-integration.outbox.defaults.hourly_send_limit')])),
                    TextInput::make('daily_send_limit')
                        ->label($this->labelWithInfo(
                            __('filament/pages/email-accounts.settings.daily_send_limit.label'),
                            __('filament/pages/email-accounts.settings.daily_send_limit.helper_text'),
                        ))
                        ->numeric()
                        ->minValue(1)
                        ->placeholder(__('filament/pages/email-accounts.settings.daily_send_limit.placeholder', ['default' => Config::integer('email-integration.outbox.defaults.daily_send_limit')])),
                ]),
        ];
    }

    /**
     * Radio cards for the mailbox sharing tier. The tier is stored on the user,
     * not the account, and the leading card hands the decision back to the
     * workspace default.
     */
    private function sharingTierField(): ViewField
    {
        /** @var User $user */
        $user = auth()->user();

        $workspaceTier = $user->currentWorkspace->default_email_sharing_tier ?? EmailPrivacyTier::METADATA_ONLY;

        return ViewField::make('default_email_sharing_tier')
            ->label($this->labelWithInfo(__('filament/pages/email-account-settings.sharing.label'), __('filament/pages/email-account-settings.sharing.hint')))
            ->view('email-integration::forms.sharing-tier-cards')
            ->viewData([
                'ariaLabel' => __('filament/pages/email-account-settings.sharing.label'),
                'workspaceDefaultLabel' => __('filament/pages/email-account-settings.sharing.use_workspace_default'),
                'workspaceDefaultDescription' => __('filament/pages/email-account-settings.sharing.workspace_default_description', [
                    'tier' => $workspaceTier->getLabel(),
                ]),
            ]);
    }

    /**
     * Helper text lives in a tooltip on an info icon that sits right after the label
     * text. Filament's own hint/afterLabel slots are pinned to the far end of the
     * label row, which reads as unrelated to the field.
     */
    private function labelWithInfo(string $label, string $tooltip): Htmlable
    {
        $icon = svg('heroicon-o-information-circle', 'h-4 w-4 text-gray-400 dark:text-gray-500')->toHtml();

        return new HtmlString(
            e($label).' <span class="inline-flex translate-y-px align-middle" x-tooltip="{ content: '.Js::from($tooltip).', theme: $store.theme }">'.$icon.'</span>'
        );
    }

    private function blocklistField(): View
    {
        return View::make('email-integration::forms.blocklist-entries')
            ->viewData([
                'emptyHeading' => __('filament/pages/email-account-settings.blocklist.empty_heading'),
                'emptyDescription' => __('filament/pages/email-account-settings.blocklist.empty_description'),
            ]);
    }

    /**
     * @return array<int, Component>
     */
    private function blocklistFormSchema(): array
    {
        return [
            TagsInput::make('blocklist_emails')
                ->label(__('filament/pages/email-account-settings.blocklist.emails_label'))
                ->placeholder(__('filament/pages/email-account-settings.blocklist.emails_placeholder'))
                ->afterLabel(__('filament/pages/email-account-settings.blocklist.emails_after_label'))
                ->nestedRecursiveRules(['email', 'max:255'])
                ->columnSpanFull(),
            Fieldset::make(__('filament/pages/email-account-settings.blocklist.domains_label'))
                ->columns(1)
                ->columnSpanFull()
                ->schema([
                    TagsInput::make('blocklist_domains')
                        ->hiddenLabel()
                        ->placeholder(__('filament/pages/email-account-settings.blocklist.domains_placeholder'))
                        ->helperText(__('filament/pages/email-account-settings.blocklist.domains_after_label'))
                        ->nestedRecursiveRules(['regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i', 'max:255']),
                    Toggle::make('blocklist_include_subdomains')
                        ->label(__('filament/pages/email-account-settings.blocklist.include_subdomains_label'))
                        ->helperText(__('filament/pages/email-account-settings.blocklist.include_subdomains_hint'))
                        ->default(false),
                ]),
        ];
    }

    public function addBlocklistAction(): Action
    {
        return Action::make('addBlocklist')
            ->label(__('filament/pages/email-account-settings.blocklist.add'))
            ->icon(Heroicon::OutlinedPlus)
            ->size(Size::Small)
            ->modalHeading(__('filament/pages/email-account-settings.blocklist.add'))
            ->schema($this->blocklistFormSchema())
            ->action(function (array $data, UpdateConnectedAccountBlocklistAction $updateBlocklist): void {
                $updateBlocklist->execute($this->account(), $this->blocklistRowsForSave(
                    $data['blocklist_emails'] ?? [],
                    $data['blocklist_domains'] ?? [],
                    (bool) ($data['blocklist_include_subdomains'] ?? false),
                ));

                unset($this->blocklistEntries);

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/email-account-settings.blocklist.notifications.added'))
                    ->send();
            });
    }

    public function deleteBlocklistEntryAction(): Action
    {
        return Action::make('deleteBlocklistEntry')
            ->label(__('filament/pages/email-signatures.actions.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->size(Size::Small)
            ->iconButton()
            ->requiresConfirmation()
            ->action(function (array $arguments): void {
                EmailBlocklist::query()
                    ->where('connected_account_id', $this->account()->getKey())
                    ->whereKey((string) $arguments['entry_id'])
                    ->firstOrFail()
                    ->delete();

                unset($this->blocklistEntries);

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/email-account-settings.blocklist.notifications.deleted'))
                    ->send();
            });
    }

    public function setBlocklistIncludeSubdomains(string $entryId, bool $include): void
    {
        $entry = EmailBlocklist::query()
            ->where('connected_account_id', $this->account()->getKey())
            ->whereKey($entryId)
            ->firstOrFail();

        if ($entry->type !== EmailBlocklistType::DOMAIN) {
            return;
        }

        $entry->update(['include_subdomains' => $include]);

        unset($this->blocklistEntries);
    }

    /**
     * @param  array<int, string>  $newEmails
     * @param  array<int, string>  $newDomains
     * @return list<array{type: string, value: string, include_subdomains: bool}>
     */
    private function blocklistRowsForSave(array $newEmails, array $newDomains, bool $includeSubdomainsForNewDomains): array
    {
        $rows = $this->blocklistEntries
            ->map(fn (EmailBlocklist $entry): array => [
                'type' => $entry->type->value,
                'value' => $entry->value,
                'include_subdomains' => $entry->type === EmailBlocklistType::DOMAIN && $entry->include_subdomains,
            ])
            ->all();

        foreach ($newEmails as $email) {
            if (blank($email)) {
                continue;
            }

            $rows[] = [
                'type' => EmailBlocklistType::EMAIL->value,
                'value' => strtolower(trim($email)),
                'include_subdomains' => false,
            ];
        }

        foreach ($newDomains as $domain) {
            if (blank($domain)) {
                continue;
            }

            $rows[] = [
                'type' => EmailBlocklistType::DOMAIN->value,
                'value' => strtolower(trim($domain)),
                'include_subdomains' => $includeSubdomainsForNewDomains,
            ];
        }

        return array_values(
            collect($rows)
                ->unique(fn (array $row): string => $row['type'].'|'.$row['value'])
                ->all(),
        );
    }

    /**
     * @return Collection<int, EmailBlocklist>
     */
    private function loadBlocklistEntries(): Collection
    {
        return $this->blocklistQuery()
            ->with('user')
            ->latest()
            ->get();
    }

    /**
     * Signatures are managed on the spot rather than through the page's Save button:
     * each one is a card with its own edit/delete modal, so the tab can show an
     * empty state instead of a repeater with a blank first row.
     */
    private function signaturesField(): View
    {
        return View::make('email-integration::forms.signature-cards');
    }

    /**
     * @return array<int, Component|Field>
     */
    private function signatureFormSchema(): array
    {
        return [
            TextInput::make('name')
                ->label(__('filament/pages/email-signatures.fields.name'))
                ->required()
                ->maxLength(100),
            RichEditor::make('content_html')
                ->label(__('filament/pages/email-signatures.fields.content'))
                ->required()
                ->toolbarButtons(['bold', 'italic', 'underline', 'link']),
            Toggle::make('is_default')
                ->label(__('filament/pages/email-signatures.fields.is_default')),
        ];
    }

    public function createSignatureAction(): Action
    {
        return Action::make('createSignature')
            ->label(__('filament/pages/email-account-settings.signatures.add'))
            ->icon(Heroicon::OutlinedPlus)
            ->size(Size::Small)
            ->modalHeading(__('filament/pages/email-account-settings.signatures.add'))
            ->schema($this->signatureFormSchema())
            ->action(function (array $data, CreateSignatureAction $createSignature): void {
                $createSignature->execute($this->account(), $this->signaturePayload($data));

                $this->signatures = $this->loadSignatures();

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/email-signatures.notifications.created'))
                    ->send();
            });
    }

    public function editSignatureAction(): Action
    {
        return Action::make('editSignature')
            ->label(__('filament/pages/email-signatures.actions.edit'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->size(Size::Small)
            ->iconButton()
            ->modalHeading(__('filament/pages/email-signatures.actions.edit'))
            ->fillForm(fn (array $arguments): array => $this->findSignatureOrFail($arguments)
                ->only(['name', 'content_html', 'is_default']))
            ->schema($this->signatureFormSchema())
            ->action(function (array $arguments, array $data, UpdateSignatureAction $updateSignature): void {
                $updateSignature->execute($this->findSignatureOrFail($arguments), $this->signaturePayload($data));

                $this->signatures = $this->loadSignatures();

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/email-signatures.notifications.updated'))
                    ->send();
            });
    }

    public function deleteSignatureAction(): Action
    {
        return Action::make('deleteSignature')
            ->label(__('filament/pages/email-signatures.actions.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->size(Size::Small)
            ->iconButton()
            ->requiresConfirmation()
            ->action(function (array $arguments, DeleteSignatureAction $deleteSignature): void {
                $deleteSignature->execute($this->findSignatureOrFail($arguments));

                $this->signatures = $this->loadSignatures();

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/email-signatures.notifications.deleted'))
                    ->send();
            });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, content_html: string, is_default: bool}
     */
    private function signaturePayload(array $data): array
    {
        return [
            'name' => (string) $data['name'],
            'content_html' => (string) $data['content_html'],
            'is_default' => (bool) ($data['is_default'] ?? false),
        ];
    }

    /** @param array<string, mixed> $arguments */
    private function findSignatureOrFail(array $arguments): EmailSignature
    {
        /** @var EmailSignature */
        return $this->signaturesQuery()->findOrFail((string) $arguments['signature_id']);
    }

    /**
     * @return Collection<int, EmailSignature>
     */
    private function loadSignatures(): Collection
    {
        return $this->signaturesQuery()->latest()->get();
    }

    public function saveAction(): Action
    {
        return Action::make('save')
            ->label(__('filament/pages/email-account-settings.actions.save'))
            ->requiresConfirmation(fn (): bool => $this->accountSharingTierChanged())
            ->modalHeading(fn (): ?string => $this->accountSharingTierChanged()
                ? SharingTierChangeConfirmation::modalHeading()
                : null)
            ->modalDescription(fn (): ?string => $this->accountSharingTierChanged()
                ? SharingTierChangeConfirmation::modalDescription($this->resolvedAccountSharingTier())
                : null)
            ->schema(fn (): array => $this->accountSharingTierChanged()
                ? SharingTierChangeConfirmation::schema($this->resolvedAccountSharingTier())
                : [])
            ->action(function (
                UpdateConnectedAccountSettingsAction $updateSettings,
            ): void {
                $data = $this->form->getState();

                /** @var User $user */
                $user = auth()->user();

                $sharingPreferenceChanged = $this->accountSharingPreferenceChanged();

                $updateSettings->execute($this->account(), $data);

                if ($sharingPreferenceChanged) {
                    $tier = $data['default_email_sharing_tier'] ?? null;

                    resolve(SaveUserEmailSharingDefaultAction::class)->execute(
                        $user,
                        $this->storedSharingTierFromForm($tier),
                        $this->privacy()->tierFromPreference($tier, $user),
                    );
                }

                $this->account()->refresh();

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/email-account-settings.notifications.saved'))
                    ->send();
            });
    }

    private function accountSharingTierChanged(): bool
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->resolvedAccountSharingTier() !== $this->privacy()->effectiveSharingTierForUser($user);
    }

    private function accountSharingPreferenceChanged(): bool
    {
        /** @var User $user */
        $user = auth()->user();

        $data = $this->form->getState();

        return $this->storedSharingTierFromForm($data['default_email_sharing_tier'] ?? null)
            !== $user->default_email_sharing_tier;
    }

    private function storedSharingTierFromForm(mixed $tierValue): ?EmailPrivacyTier
    {
        return match (true) {
            $tierValue instanceof EmailPrivacyTier => $tierValue,
            filled($tierValue) => EmailPrivacyTier::from((string) $tierValue),
            default => null,
        };
    }

    private function resolvedAccountSharingTier(): EmailPrivacyTier
    {
        $data = $this->form->getState();

        /** @var User $user */
        $user = auth()->user();

        return $this->privacy()->tierFromPreference($data['default_email_sharing_tier'] ?? null, $user);
    }

    private function privacy(): PrivacyService
    {
        return resolve(PrivacyService::class);
    }

    /**
     * @return Builder<EmailSignature>
     */
    private function signaturesQuery(): Builder
    {
        return EmailSignature::query()
            ->where('connected_account_id', $this->account()->getKey())
            ->where('user_id', $this->account()->user_id)
            ->where('workspace_id', $this->account()->workspace_id);
    }

    /**
     * @return Builder<EmailBlocklist>
     */
    private function blocklistQuery(): Builder
    {
        return EmailBlocklist::query()
            ->where('connected_account_id', $this->account()->getKey());
    }
}
