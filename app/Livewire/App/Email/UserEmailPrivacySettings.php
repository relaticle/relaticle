<?php

declare(strict_types=1);

namespace App\Livewire\App\Email;

use App\Livewire\BaseLivewireComponent;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\View\View;
use Relaticle\EmailIntegration\Actions\UpdateUserEmailPrivacySettingsAction;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\EmailBlocklist;

final class UserEmailPrivacySettings extends BaseLivewireComponent
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $user = $this->authUser();

        $blocklist = EmailBlocklist::query()
            ->where('user_id', $user->getKey())
            ->where('team_id', $user->currentTeam->getKey())
            ->get(['type', 'value']);

        $this->form->fill([
            'default_email_sharing_tier' => $user->default_email_sharing_tier?->value,
            'blocklist_emails' => $blocklist
                ->where('type', EmailBlocklistType::EMAIL)
                ->pluck('value')
                ->values()
                ->all(),
            'blocklist_domains' => $blocklist
                ->where('type', EmailBlocklistType::DOMAIN)
                ->pluck('value')
                ->values()
                ->all(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('email/privacy-settings.sharing_preference.heading'))
                    ->aside()
                    ->description(__('email/privacy-settings.sharing_preference.description'))
                    ->schema([
                        ViewField::make('default_email_sharing_tier')
                            ->label(__('email/privacy-settings.sharing_preference.tier_label'))
                            ->view('email-integration::forms.sharing-tier-cards')
                            ->viewData([
                                'ariaLabel' => __('email/privacy-settings.sharing_preference.tier_label'),
                                'workspaceDefaultLabel' => __('email/privacy-settings.sharing_preference.use_workspace_default'),
                                'workspaceDefaultDescription' => __('email/privacy-settings.sharing_preference.workspace_default_description', [
                                    'tier' => ($this->authUser()->currentTeam->default_email_sharing_tier ?? EmailPrivacyTier::METADATA_ONLY)->getLabel(),
                                ]),
                            ]),
                        Actions::make([
                            Action::make('saveTier')
                                ->label(__('email/privacy-settings.actions.save'))
                                ->submit('save'),
                        ]),
                    ]),

                Section::make(__('email/privacy-settings.blocklist.heading'))
                    ->aside()
                    ->description(__('email/privacy-settings.blocklist.description'))
                    ->schema([
                        TagsInput::make('blocklist_emails')
                            ->label(__('email/privacy-settings.blocklist.emails_label'))
                            ->placeholder(__('email/privacy-settings.blocklist.emails_placeholder'))
                            ->helperText(__('email/privacy-settings.blocklist.emails_helper'))
                            ->nestedRecursiveRules(['email', 'max:255']),
                        TagsInput::make('blocklist_domains')
                            ->label(__('email/privacy-settings.blocklist.domains_label'))
                            ->placeholder(__('email/privacy-settings.blocklist.domains_placeholder'))
                            ->helperText(__('email/privacy-settings.blocklist.domains_helper'))
                            ->nestedRecursiveRules(['regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i', 'max:255']),
                        Actions::make([
                            Action::make('saveBlocklist')
                                ->label(__('email/privacy-settings.actions.save'))
                                ->submit('save'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(UpdateUserEmailPrivacySettingsAction $action): void
    {
        $data = $this->form->getState();

        $tierValue = $data['default_email_sharing_tier'] ?? null;
        $defaultTier = match (true) {
            $tierValue instanceof EmailPrivacyTier => $tierValue,
            filled($tierValue) => EmailPrivacyTier::from($tierValue),
            default => null,
        };

        $action->execute($this->authUser(), $defaultTier, $this->blocklistRows($data));

        $this->sendNotification(__('email/privacy-settings.notifications.saved'));
    }

    public function render(): View
    {
        return view('livewire.app.email.user-email-privacy-settings');
    }

    /**
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
}
