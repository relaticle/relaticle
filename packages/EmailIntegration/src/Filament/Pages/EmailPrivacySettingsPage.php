<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use App\Enums\WorkspaceRole;
use App\Features\EmailIntegration;
use App\Filament\Pages\Concerns\HasWorkspaceSettingsNavigation;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Url;
use Relaticle\EmailIntegration\Actions\SaveTeamEmailSharingDefaultAction;
use Relaticle\EmailIntegration\Actions\UpdateTeamContactCreationSettingsAction;
use Relaticle\EmailIntegration\Actions\UpdateTeamEmailPrivacySettingsAction;
use Relaticle\EmailIntegration\Actions\UpdateTeamEmailVisibilityAction;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailVisibilityEnforcement;
use Relaticle\EmailIntegration\Models\TeamEmailBlocklist;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;
use Relaticle\EmailIntegration\Services\PrivacyService;
use Relaticle\EmailIntegration\Support\SharingTierChangeConfirmation;

final class EmailPrivacySettingsPage extends Page implements HasSchemas
{
    use HasWorkspaceSettingsNavigation;
    use InteractsWithSchemas;

    /**
     * Workspace-wide privacy and record-creation settings may only be viewed
     * and changed by the team owner or an admin. Mirrors the write guards in
     * {@see UpdateTeamEmailPrivacySettingsAction} and
     * {@see UpdateTeamContactCreationSettingsAction}; other roles use the
     * per-user "My Email Privacy" page instead.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function canAccess(array $parameters = []): bool
    {
        if (! Feature::active(EmailIntegration::class) || ! parent::canAccess()) {
            return false;
        }

        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        $team = $user->currentWorkspace;

        return $team instanceof Workspace
            && ($user->ownsWorkspace($team) || $user->hasWorkspaceRole($team, WorkspaceRole::Admin->value));
    }

    protected string $view = 'email-integration::filament.pages.workspace-email-settings';

    protected static ?string $slug = 'workspace/email/privacy';

    protected static ?string $title = null;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function getTitle(): string
    {
        return __('workspaces.tabs.email');
    }

    public static function getLabel(): string
    {
        return __('workspaces.tabs.email');
    }

    public string $default_email_sharing_tier = 'metadata_only';

    public string $contact_creation_mode = 'selective';

    public bool $auto_create_companies = true;

    /** @var array<string, Heroicon> */
    public const array TABS = [
        'visibility' => Heroicon::OutlinedNoSymbol,
        'sharing' => Heroicon::OutlinedShieldCheck,
        'record_creation' => Heroicon::OutlinedUserPlus,
    ];

    #[Url]
    public string $tab = 'visibility';

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();
        $team = $user->currentWorkspace;

        $this->default_email_sharing_tier = ($team->default_email_sharing_tier ?? EmailPrivacyTier::METADATA_ONLY)->value;

        $this->contact_creation_mode = ($team->contact_creation_mode ?? ContactCreationMode::Selective)->value;
        $this->auto_create_companies = $team->auto_create_companies;

        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'visibility';
        }
    }

    public function setTab(string $tab): void
    {
        if (! array_key_exists($tab, self::TABS)) {
            return;
        }

        $this->tab = $tab;
    }

    public function updatedContactCreationMode(): void
    {
        if ($this->contact_creation_mode === ContactCreationMode::None->value) {
            $this->auto_create_companies = false;
        }
    }

    public function saveAction(): Action
    {
        return Action::make('save')
            ->label(__('filament/pages/email-privacy-settings.actions.save'))
            ->requiresConfirmation(fn (): bool => $this->shouldConfirmSave())
            ->modalHeading(fn (): ?string => $this->shouldConfirmSave()
                ? SharingTierChangeConfirmation::modalHeading()
                : null)
            ->modalWidth(Width::Small)
            ->modalDescription(fn (): ?string => $this->shouldConfirmSave()
                ? SharingTierChangeConfirmation::modalDescription(
                    EmailPrivacyTier::from($this->default_email_sharing_tier),
                )
                : null)
            ->schema(fn (): array => $this->shouldConfirmSave()
                ? SharingTierChangeConfirmation::schema(
                    EmailPrivacyTier::from($this->default_email_sharing_tier),
                )
                : [])
            ->action(function (): void {
                /** @var User $user */
                $user = auth()->user();
                $team = $user->currentWorkspace;

                $saved = match ($this->tab) {
                    'sharing' => $this->persistWorkspaceSharingSettings($team, $user),
                    'record_creation' => $this->persistContactCreationSettings(),
                    default => false,
                };

                if (! $saved) {
                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/email-privacy-settings.notifications.saved'))
                    ->send();
            });
    }

    private function shouldConfirmSave(): bool
    {
        return $this->tab === 'sharing' && $this->workspaceSharingTierChanged();
    }

    private function workspaceSharingTierChanged(): bool
    {
        /** @var User $user */
        $user = auth()->user();
        $team = $user->currentWorkspace;

        $newTier = EmailPrivacyTier::from($this->default_email_sharing_tier);

        return $newTier !== $this->privacy()->workspaceSharingTier($team);
    }

    private function persistWorkspaceSharingSettings(Workspace $team, User $user): bool
    {
        $newTier = EmailPrivacyTier::from($this->default_email_sharing_tier);

        resolve(SaveTeamEmailSharingDefaultAction::class)->execute($team, $user, $newTier);

        return true;
    }

    public function addVisibilityContactAction(): Action
    {
        return Action::make('addVisibilityContact')
            ->label(__('filament/pages/email-privacy-settings.visibility.add'))
            ->icon(Heroicon::OutlinedPlus)
            ->size(Size::Small)
            ->modalHeading(__('filament/pages/email-privacy-settings.visibility.add'))
            ->schema([
                TagsInput::make('visibility_emails')
                    ->label(__('filament/pages/email-privacy-settings.visibility.emails_label'))
                    ->placeholder(__('filament/pages/email-privacy-settings.visibility.emails_placeholder'))
                    ->afterLabel(__('filament/pages/email-privacy-settings.visibility.emails_after_label'))
                    ->nestedRecursiveRules(['email', 'max:255'])
                    ->columnSpanFull(),
                Fieldset::make(__('filament/pages/email-privacy-settings.visibility.domains_label'))
                    ->columns(1)
                    ->columnSpanFull()
                    ->schema([
                        TagsInput::make('visibility_domains')
                            ->hiddenLabel()
                            ->placeholder(__('filament/pages/email-privacy-settings.visibility.domains_placeholder'))
                            ->helperText(__('filament/pages/email-privacy-settings.visibility.domains_after_label')),
                        Toggle::make('visibility_include_subdomains')
                            ->label(__('filament/pages/email-privacy-settings.visibility.include_subdomains_label'))
                            ->helperText(__('filament/pages/email-privacy-settings.visibility.include_subdomains_hint'))
                            ->default(false),
                    ]),
            ])
            ->action(function (array $data): void {
                /** @var User $user */
                $user = auth()->user();
                $team = $user->currentWorkspace;

                resolve(UpdateTeamEmailVisibilityAction::class)->execute(
                    $team,
                    $user,
                    $this->mergedVisibilityEntries(
                        $team,
                        $data['visibility_emails'] ?? [],
                        $data['visibility_domains'] ?? [],
                        (bool) ($data['visibility_include_subdomains'] ?? false),
                    ),
                );

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/email-privacy-settings.visibility.notifications.added'))
                    ->send();

                $this->dispatch('visibility-entries-updated');
            });
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make(__('filament/pages/email-privacy-settings.visibility.heading'))
                ->description(__('filament/pages/email-privacy-settings.visibility.description'))
                ->compact()
                ->visible(fn (): bool => $this->tab === 'visibility')
                ->headerActions([
                    $this->addVisibilityContactAction(...),
                ])
                ->schema([
                    View::make('email-integration::livewire.email-visibility-table-embed'),
                ]),

            Section::make(__('filament/pages/email-privacy-settings.workspace_default.heading'))
                ->description(__('filament/pages/email-privacy-settings.workspace_default.description'))
                ->compact()
                ->visible(fn (): bool => $this->tab === 'sharing')
                ->schema([
                    ViewField::make('default_email_sharing_tier')
                        ->label(__('filament/pages/email-privacy-settings.workspace_default.tier_label'))
                        ->view('email-integration::forms.sharing-tier-cards')
                        ->viewData([
                            'ariaLabel' => __('filament/pages/email-privacy-settings.workspace_default.tier_label'),
                        ]),
                ])
                ->footerActions([
                    $this->saveAction(),
                ])
                ->footerActionsAlignment(Alignment::End),

            Section::make(__('filament/pages/email-privacy-settings.record_creation.heading'))
                ->description(__('filament/pages/email-privacy-settings.record_creation.description'))
                ->compact()
                ->visible(fn (): bool => $this->tab === 'record_creation')
                ->schema([
                    ViewField::make('contact_creation_mode')
                        ->hiddenLabel()
                        ->view('email-integration::forms.contact-creation-cards')
                        ->viewData([
                            'ariaLabel' => __('filament/pages/email-privacy-settings.record_creation.heading'),
                        ]),
                    ViewField::make('auto_create_companies')
                        ->hiddenLabel()
                        ->view('email-integration::forms.company-creation-card'),
                ])
                ->footerActions([
                    $this->saveAction(),
                ])
                ->footerActionsAlignment(Alignment::End),
        ]);
    }

    private function persistContactCreationSettings(): bool
    {
        $mode = ContactCreationMode::tryFrom($this->contact_creation_mode);

        if ($mode === null) {
            return false;
        }

        /** @var User $user */
        $user = auth()->user();

        if ($mode === ContactCreationMode::None) {
            $this->auto_create_companies = false;
        }

        resolve(UpdateTeamContactCreationSettingsAction::class)->execute(
            $user->currentWorkspace,
            $user,
            $mode,
            $this->auto_create_companies,
        );

        return true;
    }

    private function privacy(): PrivacyService
    {
        return resolve(PrivacyService::class);
    }

    /**
     * @param  array<int, string>  $newEmails
     * @param  array<int, string>  $newDomains
     * @return array<int, array{type: string, value: string, enforcement_level: EmailVisibilityEnforcement, include_subdomains: bool}>
     */
    private function mergedVisibilityEntries(
        Workspace $team,
        array $newEmails,
        array $newDomains,
        bool $includeSubdomainsForNewDomains,
    ): array {
        $enforcement = EmailVisibilityEnforcement::Protected;

        $entries = TeamEmailBlocklist::query()
            ->where('workspace_id', $team->getKey())
            ->latest()
            ->get()
            ->map(fn (TeamEmailBlocklist $entry): array => [
                'type' => $entry->type->value,
                'value' => $entry->value,
                'enforcement_level' => $entry->enforcement_level,
                'include_subdomains' => $entry->type === EmailBlocklistType::DOMAIN && $entry->include_subdomains,
            ])
            ->all();

        foreach ($newEmails as $email) {
            if (blank($email)) {
                continue;
            }

            $entries[] = [
                'type' => EmailBlocklistType::EMAIL->value,
                'value' => strtolower(trim($email)),
                'enforcement_level' => $enforcement,
                'include_subdomains' => false,
            ];
        }

        foreach ($newDomains as $domain) {
            if (blank($domain)) {
                continue;
            }

            $normalized = resolve(EmailVisibilityService::class)->normalizeDomainInput((string) $domain);

            if ($normalized === null) {
                continue;
            }

            $entries[] = [
                'type' => EmailBlocklistType::DOMAIN->value,
                'value' => $normalized,
                'enforcement_level' => $enforcement,
                'include_subdomains' => $includeSubdomainsForNewDomains,
            ];
        }

        return collect($entries)
            ->unique(fn (array $entry): string => $entry['type'].'|'.$entry['value'])
            ->values()
            ->all();
    }
}
