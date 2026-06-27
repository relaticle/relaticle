<?php

declare(strict_types=1);

namespace App\Livewire\App\Email;

use App\Livewire\BaseLivewireComponent;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
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
            ->get(['id', 'type', 'value'])
            ->toArray();

        $this->form->fill([
            'default_email_sharing_tier' => $user->default_email_sharing_tier?->value,
            'blocklist' => $blocklist,
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
                        Select::make('default_email_sharing_tier')
                            ->label(__('email/privacy-settings.sharing_preference.tier_label'))
                            ->options(
                                collect(EmailPrivacyTier::cases())
                                    ->mapWithKeys(fn (EmailPrivacyTier $tier): array => [$tier->value => $tier->getLabel()])
                                    ->all()
                            )
                            ->placeholder(__('email/privacy-settings.sharing_preference.use_workspace_default'))
                            ->helperText(fn (): string => __('email/privacy-settings.sharing_preference.workspace_default_hint', [
                                'tier' => ($this->authUser()->currentTeam->default_email_sharing_tier ?? EmailPrivacyTier::METADATA_ONLY)->getLabel(),
                            ])),
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
                        Repeater::make('blocklist')
                            ->hiddenLabel()
                            ->schema([
                                Select::make('type')
                                    ->label(__('email/privacy-settings.blocklist.type_label'))
                                    ->options(
                                        collect(EmailBlocklistType::cases())
                                            ->mapWithKeys(fn (EmailBlocklistType $type): array => [$type->value => $type->getLabel()])
                                            ->all()
                                    )
                                    ->required(),
                                Select::make('value')
                                    ->label(__('email/privacy-settings.blocklist.value_label'))
                                    ->placeholder(__('email/privacy-settings.blocklist.value_placeholder'))
                                    ->required()
                                    ->searchable()
                                    ->allowHtml(false)
                                    ->createOptionUsing(fn (string $value): string => strtolower(trim($value)))
                                    ->createOptionForm([])
                                    ->getSearchResultsUsing(fn (string $search): array => [strtolower(trim($search)) => strtolower(trim($search))])
                                    ->getOptionLabelUsing(fn (string $value): string => $value),
                            ])
                            ->columns(2)
                            ->addActionLabel(__('email/privacy-settings.blocklist.add_entry'))
                            ->addAction(fn (Action $action): Action => $action->icon('heroicon-m-plus'))
                            ->reorderable(false),
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

        $action->execute($this->authUser(), $defaultTier, $data['blocklist'] ?? []);

        $this->sendNotification(__('email/privacy-settings.notifications.saved'));
    }

    public function render(): View
    {
        return view('livewire.app.email.user-email-privacy-settings');
    }
}
