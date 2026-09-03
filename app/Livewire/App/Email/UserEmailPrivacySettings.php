<?php

declare(strict_types=1);

namespace App\Livewire\App\Email;

use App\Livewire\BaseLivewireComponent;
use Filament\Actions\Action;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\View\View;
use Relaticle\EmailIntegration\Actions\UpdateUserEmailPrivacySettingsAction;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;

final class UserEmailPrivacySettings extends BaseLivewireComponent
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $user = $this->authUser();

        $this->form->fill([
            'default_email_sharing_tier' => $user->default_email_sharing_tier?->value,
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

        $action->execute($this->authUser(), $defaultTier);

        $this->sendNotification(__('email/privacy-settings.notifications.saved'));
    }

    public function render(): View
    {
        return view('livewire.app.email.user-email-privacy-settings');
    }
}
