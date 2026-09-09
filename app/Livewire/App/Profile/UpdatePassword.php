<?php

declare(strict_types=1);

namespace App\Livewire\App\Profile;

use App\Filament\Actions\ConfirmIdentityAction;
use App\Livewire\BaseLivewireComponent;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

final class UpdatePassword extends BaseLivewireComponent
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function form(Schema $schema): Schema
    {
        $hasPassword = $this->authUser()->hasPassword();

        return $schema
            ->schema([
                Section::make($hasPassword ? __('profile.sections.update_password.title') : __('profile.sections.set_password.title'))
                    ->aside()
                    ->description($hasPassword ? __('profile.sections.update_password.description') : __('profile.sections.set_password.description'))
                    ->schema([
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
                            $this->saveAction(),
                        ]),
                    ]),
            ])
            ->statePath('data')
            ->model($this->authUser());
    }

    public function updatePassword(): void
    {
        $this->mountAction('save');
    }

    public function saveAction(): ConfirmIdentityAction
    {
        return ConfirmIdentityAction::make('save')
            ->label(__('profile.actions.save'))
            ->modalHeading(__('auth.confirm.heading'))
            ->modalDescription(__('auth.confirm.description'))
            ->alwaysConfirm()
            ->operation('set_password')
            ->beforeFormFilled(function (): void {
                $this->form->validate();
            })
            ->confirmedUsing(function (): void {
                $this->savePassword();
            });
    }

    private function savePassword(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->sendRateLimitedNotification($exception);

            return;
        }

        $this->form->validate();

        $data = $this->form->getState();

        resolve(UpdatesUserPasswords::class)->update($this->authUser(), $data);

        if (request()->hasSession() && filled($data['password'])) {
            request()->session()->put(['password_hash_'.Filament::getAuthGuard() => $this->authUser()->getAuthPassword()]);
        }

        $this->reset('data');

        $this->sendNotification();
    }

    public function render(): View
    {
        return view('livewire.app.profile.update-password');
    }
}
