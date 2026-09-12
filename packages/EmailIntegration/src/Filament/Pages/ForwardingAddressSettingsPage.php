<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use App\Models\Team;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Relaticle\EmailIntegration\Actions\EnsureTeamForwardingAddressAction;
use Relaticle\EmailIntegration\Actions\UpdateUserForwardingBlocklistAction;
use Relaticle\EmailIntegration\Actions\UpdateUserForwardingFullAccessGrantsAction;
use Relaticle\EmailIntegration\Actions\UpdateUserForwardingSettingsAction;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Clusters\EmailSettings;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailFeatureFlag;
use Relaticle\EmailIntegration\Models\TeamForwardingAddress;
use Relaticle\EmailIntegration\Models\UserForwardingBlocklist;
use Relaticle\EmailIntegration\Models\UserForwardingFullAccessGrant;
use Relaticle\EmailIntegration\Models\UserForwardingSettings;

/**
 * @property-read Schema $form
 * @property-read Collection<int, UserForwardingBlocklist> $blocklistEntries
 */
final class ForwardingAddressSettingsPage extends Page implements HasSchemas
{
    use HasEmailFeatureFlag, InteractsWithSchemas;

    protected string $view = 'email-integration::filament.pages.forwarding-address-settings';

    protected static ?string $cluster = EmailSettings::class;

    protected static ?string $slug = 'forwarding';

    protected static bool $shouldRegisterNavigation = false;

    protected ?string $heading = '';

    protected ?string $subheading = null;

    public TeamForwardingAddress $forwardingAddress;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(EnsureTeamForwardingAddressAction $ensureForwardingAddress): void
    {
        /** @var User $user */
        $user = auth()->user();
        $team = $user->currentTeam;

        abort_unless($team instanceof Team, 404);

        $this->forwardingAddress = $ensureForwardingAddress->execute($team);

        $settings = UserForwardingSettings::query()
            ->where('user_id', $user->getKey())
            ->where('team_id', $team->getKey())
            ->first();

        $this->form->fill([
            'sharing_tier' => $settings?->sharing_tier->value ?? EmailPrivacyTier::METADATA_ONLY->value,
            'full_access_user_ids' => UserForwardingFullAccessGrant::query()
                ->where('user_id', $user->getKey())
                ->where('team_id', $team->getKey())
                ->pluck('granted_user_id')
                ->all(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [
            EmailAccountsPage::getUrl() => (string) __('filament/pages/email-accounts.navigation_label'),
            '' => $this->forwardingAddress->fullAddress(),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make()
                    ->tabs([
                        Tab::make(__('filament/pages/forwarding-address-settings.tabs.general'))
                            ->icon(Heroicon::OutlinedCog6Tooth)
                            ->schema([
                                Section::make(__('filament/pages/forwarding-address-settings.visibility.label'))
                                    ->description(__('filament/pages/forwarding-address-settings.visibility.hint'))
                                    ->compact()
                                    ->schema([$this->sharingTierField()]),
                                Section::make(__('filament/pages/forwarding-address-settings.full_access.label'))
                                    ->description(__('filament/pages/forwarding-address-settings.full_access.hint'))
                                    ->compact()
                                    ->schema([
                                        Select::make('full_access_user_ids')
                                            ->label(__('filament/pages/forwarding-address-settings.full_access.add'))
                                            ->multiple()
                                            ->searchable()
                                            ->options(fn (): array => $this->teammateOptions())
                                            ->native(false),
                                        View::make('email-integration::forms.forwarding-full-access-hint'),
                                    ]),
                            ]),
                        Tab::make(__('filament/pages/forwarding-address-settings.tabs.blocklist'))
                            ->icon(Heroicon::OutlinedNoSymbol)
                            ->schema([
                                Section::make(__('filament/pages/forwarding-address-settings.blocklist.label'))
                                    ->description(__('filament/pages/forwarding-address-settings.blocklist.hint'))
                                    ->compact()
                                    ->headerActions([
                                        fn (): Action => $this->addBlocklistAction()
                                            ->visible(fn (): bool => $this->blocklistEntries->isNotEmpty()),
                                    ])
                                    ->schema([$this->blocklistField()]),
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    private function sharingTierField(): ViewField
    {
        return ViewField::make('sharing_tier')
            ->view('email-integration::forms.sharing-tier-cards')
            ->viewData([
                'ariaLabel' => __('filament/pages/forwarding-address-settings.visibility.label'),
            ]);
    }

    private function blocklistField(): View
    {
        return View::make('email-integration::forms.forwarding-blocklist-entries')
            ->viewData([
                'emptyHeading' => __('filament/pages/forwarding-address-settings.blocklist.empty_heading'),
                'emptyDescription' => __('filament/pages/forwarding-address-settings.blocklist.empty_description'),
            ]);
    }

    /**
     * @return array<int, TagsInput>
     */
    private function blocklistFormSchema(): array
    {
        return [
            TagsInput::make('blocklist_emails')
                ->label(__('filament/pages/forwarding-address-settings.blocklist.emails_label'))
                ->placeholder(__('filament/pages/forwarding-address-settings.blocklist.emails_placeholder'))
                ->afterLabel(__('filament/pages/forwarding-address-settings.blocklist.emails_after_label'))
                ->nestedRecursiveRules(['email', 'max:255']),
            TagsInput::make('blocklist_domains')
                ->label(__('filament/pages/forwarding-address-settings.blocklist.domains_label'))
                ->placeholder(__('filament/pages/forwarding-address-settings.blocklist.domains_placeholder'))
                ->afterLabel(__('filament/pages/forwarding-address-settings.blocklist.domains_after_label'))
                ->nestedRecursiveRules(['regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i', 'max:255']),
        ];
    }

    public function addBlocklistAction(): Action
    {
        return Action::make('addBlocklist')
            ->label(__('filament/pages/forwarding-address-settings.blocklist.add'))
            ->icon(Heroicon::OutlinedPlus)
            ->size(Size::Small)
            ->modalHeading(__('filament/pages/forwarding-address-settings.blocklist.add'))
            ->schema($this->blocklistFormSchema())
            ->action(function (array $data, UpdateUserForwardingBlocklistAction $updateBlocklist): void {
                [$emails, $domains] = $this->mergedBlocklistValues(
                    $data['blocklist_emails'] ?? [],
                    $data['blocklist_domains'] ?? [],
                );

                $updateBlocklist->execute($this->authUser(), $this->team(), $this->blocklistRowsFromValues($emails, $domains));

                unset($this->blocklistEntries);

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/forwarding-address-settings.blocklist.notifications.added'))
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
                UserForwardingBlocklist::query()
                    ->where('user_id', $this->authUser()->getKey())
                    ->where('team_id', $this->team()->getKey())
                    ->whereKey((string) $arguments['entry_id'])
                    ->firstOrFail()
                    ->delete();

                unset($this->blocklistEntries);

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/forwarding-address-settings.blocklist.notifications.deleted'))
                    ->send();
            });
    }

    public function saveAction(): Action
    {
        return Action::make('save')
            ->label(__('filament/pages/email-accounts.settings.submit_label'))
            ->action(function (
                UpdateUserForwardingSettingsAction $updateSettings,
                UpdateUserForwardingFullAccessGrantsAction $updateGrants,
            ): void {
                $data = $this->form->getState();
                $tier = EmailPrivacyTier::from((string) ($data['sharing_tier'] ?? EmailPrivacyTier::METADATA_ONLY->value));

                $updateSettings->execute($this->authUser(), $this->team(), $tier);
                $granteeIds = array_values(array_map(strval(...), $data['full_access_user_ids'] ?? []));

                $updateGrants->execute(
                    $this->authUser(),
                    $this->team(),
                    $granteeIds,
                );

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/forwarding-address-settings.notifications.saved'))
                    ->send();
            });
    }

    /**
     * @return Collection<int, UserForwardingBlocklist>
     */
    #[Computed]
    public function blocklistEntries(): Collection
    {
        return UserForwardingBlocklist::query()
            ->where('user_id', $this->authUser()->getKey())
            ->where('team_id', $this->team()->getKey())
            ->latest()
            ->get();
    }

    /**
     * @return array<string, string>
     */
    private function teammateOptions(): array
    {
        return $this->team()
            ->allUsers()
            ->reject(fn (User $member): bool => $member->getKey() === $this->authUser()->getKey())
            ->mapWithKeys(fn (User $member): array => [$member->getKey() => $member->name])
            ->all();
    }

    /**
     * @param  array<int, string>  $newEmails
     * @param  array<int, string>  $newDomains
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function mergedBlocklistValues(array $newEmails, array $newDomains): array
    {
        $emails = $this->blocklistEntries
            ->where('type', EmailBlocklistType::EMAIL)
            ->pluck('value')
            ->merge($newEmails)
            ->map(fn (mixed $value): string => strtolower(trim((string) $value)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $domains = $this->blocklistEntries
            ->where('type', EmailBlocklistType::DOMAIN)
            ->pluck('value')
            ->merge($newDomains)
            ->map(fn (mixed $value): string => strtolower(trim((string) $value)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [$emails, $domains];
    }

    /**
     * @param  array<int, string>  $emails
     * @param  array<int, string>  $domains
     * @return list<array{type: string, value: string}>
     */
    private function blocklistRowsFromValues(array $emails, array $domains): array
    {
        return array_values(collect([
            EmailBlocklistType::EMAIL->value => $emails,
            EmailBlocklistType::DOMAIN->value => $domains,
        ])
            ->flatMap(fn (array $values, string $type): array => array_map(
                fn (string $value): array => ['type' => $type, 'value' => $value],
                $values,
            ))
            ->all());
    }

    private function authUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    private function team(): Team
    {
        $team = $this->authUser()->currentTeam;

        abort_unless($team instanceof Team, 404);

        return $team;
    }
}
