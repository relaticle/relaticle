<?php

declare(strict_types=1);

namespace App\Livewire\App\Email;

use App\Livewire\BaseLivewireComponent;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\View\View;
use Relaticle\EmailIntegration\Actions\SaveUserEmailSharingDefaultAction;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Services\PrivacyService;
use Relaticle\EmailIntegration\Support\SharingTierChangeConfirmation;

final class UserEmailPrivacySettings extends BaseLivewireComponent implements HasActions
{
    use InteractsWithActions;

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
                                    'tier' => ($this->authUser()->currentWorkspace->default_email_sharing_tier ?? EmailPrivacyTier::METADATA_ONLY)->getLabel(),
                                ]),
                            ]),
                        Actions::make([
                            $this->saveTierAction(),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function saveTierAction(): Action
    {
        return Action::make('saveTier')
            ->label(__('email/privacy-settings.actions.save'))
            ->requiresConfirmation(fn (): bool => $this->userSharingTierChanged())
            ->modalHeading(SharingTierChangeConfirmation::modalHeading())
            ->modalDescription(fn (): string => SharingTierChangeConfirmation::modalDescription(
                $this->resolvedUserSharingTier(),
            ))
            ->schema(fn (): array => SharingTierChangeConfirmation::schema(
                $this->resolvedUserSharingTier(),
            ))
            ->action(fn (): null => $this->persistUserSharingTier());
    }

    private function userSharingTierChanged(): bool
    {
        return $this->resolvedUserSharingTier() !== $this->privacy()->effectiveSharingTierForUser($this->authUser());
    }

    private function resolvedUserSharingTier(): EmailPrivacyTier
    {
        $data = $this->form->getState();

        return $this->privacy()->tierFromPreference($data['default_email_sharing_tier'] ?? null, $this->authUser());
    }

    private function persistUserSharingTier(): void
    {
        $user = $this->authUser();
        $data = $this->form->getState();
        $tierValue = $data['default_email_sharing_tier'] ?? null;
        $storedTier = match (true) {
            $tierValue instanceof EmailPrivacyTier => $tierValue,
            filled($tierValue) => EmailPrivacyTier::from((string) $tierValue),
            default => null,
        };

        resolve(SaveUserEmailSharingDefaultAction::class)->execute(
            $user,
            $storedTier,
            $this->privacy()->tierFromPreference($tierValue, $user),
        );

        $this->sendNotification(__('email/privacy-settings.notifications.saved'));
    }

    private function privacy(): PrivacyService
    {
        return resolve(PrivacyService::class);
    }

    public function render(): View
    {
        return view('livewire.app.email.user-email-privacy-settings');
    }
}
