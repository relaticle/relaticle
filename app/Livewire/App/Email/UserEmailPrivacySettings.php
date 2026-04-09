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

    public function save(): void
    {
        $data = $this->form->getState();
        $user = $this->authUser();
        $teamId = $user->currentTeam->getKey();

        $user->update([
            'default_email_sharing_tier' => $data['default_email_sharing_tier'] ?: null,
        ]);

        EmailBlocklist::query()
            ->where('user_id', $user->getKey())
            ->where('team_id', $teamId)
            ->delete();

        foreach ($data['blocklist'] as $entry) {
            $value = strtolower(trim((string) $entry['value']));

            if ($value === '') {
                continue;
            }

            EmailBlocklist::query()->create([
                'user_id' => $user->getKey(),
                'team_id' => $teamId,
                'type' => $entry['type'],
                'value' => $value,
            ]);
        }

        $this->sendNotification('Email privacy settings saved.');
    }

    public function render(): View
    {
        return view('livewire.app.email.user-email-privacy-settings');
    }
}
