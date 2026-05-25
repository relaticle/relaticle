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
                    ->description('Overrides the workspace default for emails you sync. Set to blank to use the workspace default.')
                    ->schema([
                        Select::make('default_email_sharing_tier')
                            ->label('Default sharing tier')
                            ->options(
                                collect(EmailPrivacyTier::cases())
                                    ->mapWithKeys(fn (EmailPrivacyTier $tier): array => [$tier->value => $tier->getLabel()])
                                    ->prepend('Use workspace default', '')
                                    ->all()
                            )
                            ->placeholder('Use workspace default'),
                        Actions::make([
                            Action::make('saveTier')
                                ->label('Save')
                                ->submit('save'),
                        ]),
                    ]),

                Section::make('Blocked Addresses & Domains')
                    ->aside()
                    ->description('Emails involving these addresses or domains will be hidden from your view.')
                    ->schema([
                        Repeater::make('blocklist')
                            ->label('')
                            ->schema([
                                Select::make('type')
                                    ->label('Type')
                                    ->options(
                                        collect(EmailBlocklistType::cases())
                                            ->mapWithKeys(fn (EmailBlocklistType $type): array => [$type->value => $type->getLabel()])
                                            ->all()
                                    )
                                    ->required(),
                                Select::make('value')
                                    ->label('Value')
                                    ->placeholder('e.g. spam@example.com or spammy.com')
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
                                ->label('Save')
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
