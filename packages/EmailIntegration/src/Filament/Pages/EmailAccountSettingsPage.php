<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

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
use Filament\Schemas\Components\Grid;
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
use Relaticle\EmailIntegration\Actions\CreateSignatureAction;
use Relaticle\EmailIntegration\Actions\DeleteSignatureAction;
use Relaticle\EmailIntegration\Actions\UpdateConnectedAccountSettingsAction;
use Relaticle\EmailIntegration\Actions\UpdateSignatureAction;
use Relaticle\EmailIntegration\Actions\UpdateUserEmailPrivacySettingsAction;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Clusters\EmailSettings;
use Relaticle\EmailIntegration\Filament\Concerns\HasConnectedAccountActions;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailFeatureFlag;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailBlocklist;
use Relaticle\EmailIntegration\Models\EmailSignature;

/**
 * Per-account settings, reached from the "Settings" entry of an account's action
 * group on {@see EmailAccountsPage}.
 *
 * Sharing tier and blocklist are stored per user + team (not per account), so the
 * General and Blocklist tabs edit settings that apply to every mailbox this user
 * has connected — the info tooltips say so. Signatures are per account.
 *
 * @property-read Schema $form
 */
final class EmailAccountSettingsPage extends Page implements HasSchemas
{
    use HasConnectedAccountActions, HasEmailFeatureFlag, InteractsWithSchemas;

    protected string $view = 'email-integration::filament.pages.email-account-settings';

    protected static ?string $cluster = EmailSettings::class;

    protected static ?string $slug = 'accounts/{account}';

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
            'blocklist_emails' => $this->blocklistValues(EmailBlocklistType::EMAIL),
            'blocklist_domains' => $this->blocklistValues(EmailBlocklistType::DOMAIN),
        ]);

        $this->signatures = $this->loadSignatures();
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
            EmailSettings::getUrl() => (string) __('filament/clusters/email-settings.breadcrumb'),
            EmailAccountsPage::getUrl() => (string) __('filament/pages/email-accounts.navigation_label'),
            $this->account()->email_address,
        ];
    }

    public function account(): ConnectedAccount
    {
        return $this->account ??= $this->ownedAccountsQuery()->findOrFail($this->accountId);
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
                            ->schema($this->blocklistFields()),

                        Tab::make(__('filament/pages/email-account-settings.tabs.signatures'))
                            ->icon(Heroicon::OutlinedPencilSquare)
                            ->schema([$this->signaturesField()]),
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
     * Attio-style radio cards. The tier is stored on the user, not the account —
     * the leading card hands the decision back to the workspace default.
     */
    private function sharingTierField(): ViewField
    {
        /** @var User $user */
        $user = auth()->user();

        $workspaceTier = $user->currentTeam->default_email_sharing_tier ?? EmailPrivacyTier::METADATA_ONLY;

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

    /**
     * Addresses and domains get a tag input each: type, hit enter, done. Splitting
     * them by field removes the per-row "is this an address or a domain?" step the
     * repeater forced on every entry.
     *
     * @return array<int, Component|Field>
     */
    private function blocklistFields(): array
    {
        return [
            TagsInput::make('blocklist_emails')
                ->label($this->labelWithInfo(
                    __('filament/pages/email-account-settings.blocklist.emails_label'),
                    __('filament/pages/email-account-settings.blocklist.hint'),
                ))
                ->placeholder(__('filament/pages/email-account-settings.blocklist.emails_placeholder'))
                ->nestedRecursiveRules(['email', 'max:255']),

            TagsInput::make('blocklist_domains')
                ->label($this->labelWithInfo(
                    __('filament/pages/email-account-settings.blocklist.domains_label'),
                    __('filament/pages/email-account-settings.blocklist.hint'),
                ))
                ->placeholder(__('filament/pages/email-account-settings.blocklist.domains_placeholder'))
                ->nestedRecursiveRules(['regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i', 'max:255']),
        ];
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
            ->action(function (
                UpdateConnectedAccountSettingsAction $updateSettings,
                UpdateUserEmailPrivacySettingsAction $updatePrivacy,
            ): void {
                $data = $this->form->getState();

                /** @var User $user */
                $user = auth()->user();

                $updateSettings->execute($this->account(), $data);

                $tier = $data['default_email_sharing_tier'] ?? null;
                $updatePrivacy->execute(
                    $user,
                    match (true) {
                        $tier instanceof EmailPrivacyTier => $tier,
                        filled($tier) => EmailPrivacyTier::from((string) $tier),
                        default => null,
                    },
                    $this->blocklistRows($data),
                );

                $this->account()->refresh();

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/email-account-settings.notifications.saved'))
                    ->send();
            });
    }

    /**
     * @return array<int, string>
     */
    private function blocklistValues(EmailBlocklistType $type): array
    {
        return $this->blocklistQuery()
            ->where('type', $type)
            ->pluck('value')
            ->all();
    }

    /**
     * Flatten both tag inputs back into the {type, value} rows the action stores.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{type: string, value: string}>
     */
    private function blocklistRows(array $data): array
    {
        return array_values(collect([
            EmailBlocklistType::EMAIL->value => $data['blocklist_emails'] ?? [],
            EmailBlocklistType::DOMAIN->value => $data['blocklist_domains'] ?? [],
        ])
            ->flatMap(fn (array $values, string $type): array => array_map(
                fn (string $value): array => ['type' => $type, 'value' => $value],
                $values,
            ))
            ->all());
    }

    /**
     * @return Builder<EmailSignature>
     */
    private function signaturesQuery(): Builder
    {
        return EmailSignature::query()
            ->where('connected_account_id', $this->account()->getKey())
            ->where('user_id', $this->account()->user_id)
            ->where('team_id', $this->account()->team_id);
    }

    /**
     * @return Builder<EmailBlocklist>
     */
    private function blocklistQuery(): Builder
    {
        return EmailBlocklist::query()
            ->where('user_id', $this->account()->user_id)
            ->where('team_id', $this->account()->team_id);
    }
}
