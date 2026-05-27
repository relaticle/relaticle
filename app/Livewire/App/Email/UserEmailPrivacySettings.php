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
                Section::make('My Email Sharing Preference')
                    ->aside()
                    ->description(__('livewire/user-email-privacy.sharing_preference.description'))
                    ->schema([
                        Select::make('default_email_sharing_tier')
                            ->label(__('livewire/user-email-privacy.sharing_preference.fields.default_email_sharing_tier.label'))
                            ->options(
                                collect(EmailPrivacyTier::cases())
                                    ->mapWithKeys(fn (EmailPrivacyTier $tier): array => [$tier->value => $tier->getLabel()])
                                    ->prepend('Use workspace default', '')
                                    ->all()
                            )
                            ->placeholder(__('livewire/user-email-privacy.sharing_preference.fields.default_email_sharing_tier.placeholder')),
                        Actions::make([
                            Action::make('saveTier')
                                ->label(__('livewire/user-email-privacy.sharing_preference.actions.save.label'))
                                ->submit('save'),
                        ]),
                    ]),

                Section::make('Blocked Addresses & Domains')
                    ->aside()
                    ->description(__('livewire/user-email-privacy.blocklist.description'))
                    ->schema([
                        Repeater::make('blocklist')
                            ->label(__('livewire/user-email-privacy.blocklist.label'))
                            ->schema([
                                Select::make('type')
                                    ->label(__('livewire/user-email-privacy.blocklist.fields.type.label'))
                                    ->options(
                                        collect(EmailBlocklistType::cases())
                                            ->mapWithKeys(fn (EmailBlocklistType $type): array => [$type->value => $type->getLabel()])
                                            ->all()
                                    )
                                    ->required(),
                                Select::make('value')
                                    ->label(__('livewire/user-email-privacy.blocklist.fields.value.label'))
                                    ->placeholder(__('livewire/user-email-privacy.blocklist.fields.value.placeholder'))
                                    ->required()
                                    ->searchable()
                                    ->allowHtml(false)
                                    ->createOptionUsing(fn (string $value): string => strtolower(trim($value)))
                                    ->createOptionForm([])
                                    ->getSearchResultsUsing(fn (string $search): array => [strtolower(trim($search)) => strtolower(trim($search))])
                                    ->getOptionLabelUsing(fn (string $value): string => $value),
                            ])
                            ->columns(2)
                            ->addActionLabel('Add entry')
                            ->reorderable(false),
                        Actions::make([
                            Action::make('saveBlocklist')
                                ->label(__('livewire/user-email-privacy.blocklist.actions.save.label'))
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

        $this->sendNotification('Email privacy settings saved.');
    }

    public function render(): View
    {
        return view('livewire.app.email.user-email-privacy-settings');
    }
}
