<?php

declare(strict_types=1);

namespace App\Livewire\App\Profile;

use App\Livewire\BaseLivewireComponent;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

final class UpdatePassword extends BaseLivewireComponent
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        // Initialize empty form
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('profile.sections.update_password.title'))
                    ->aside()
                    ->description(__('profile.sections.update_password.description'))
                    ->schema([
                        TextInput::make('currentPassword')
                            ->label(__('profile.form.current_password.label'))
                            ->password()
                            ->revealable(filament()->arePasswordsRevealable())
                            ->required()
                            ->autocomplete('current-password')
                            ->currentPassword(),
                        TextInput::make('password')
                            ->label(__('profile.form.new_password.label'))
                            ->password()
                            ->required()
                            ->revealable(filament()->arePasswordsRevealable())
                            ->rules([Password::default(), 'confirmed'])
                            ->autocomplete('new-password')
                            ->dehydrated()
                            ->live(debounce: 500),
                        TextInput::make('password_confirmation')
                            ->label(__('profile.form.confirm_password.label'))
                            ->password()
                            ->revealable(filament()->arePasswordsRevealable())
                            ->required()
                            ->dehydrated()
                            ->visible(
                                fn (Get $get): bool => filled($get('password'))
                            ),
                        Actions::make([
                            Action::make('save')
                                ->label(__('profile.actions.save'))
                                ->submit('updatePassword'),
                        ]),
                    ]),
            ])
            ->statePath('data')
            ->model($this->authUser());
    }

    public function updatePassword(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->sendRateLimitedNotification($exception);

            return;
        }

        $this->form->validate();

        $data = $this->form->getState();

        // Update the password directly without Fortify validation
        $this->authUser()->forceFill([
            'password' => Hash::make($data['password'] ?? ''),
        ])->save();

        if (request()->hasSession() && filled($data['password'])) {
            request()->session()->put(['password_hash_'.Filament::getAuthGuard() => $this->authUser()->getAuthPassword()]);
        }

        $this->reset('data');

        $this->sendNotification();
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.app.profile.update-password');
    }
}
