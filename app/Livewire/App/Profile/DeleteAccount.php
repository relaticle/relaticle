<?php

declare(strict_types=1);

namespace App\Livewire\App\Profile;

use App\Actions\Jetstream\ScheduleUserDeletion;
use App\Livewire\BaseLivewireComponent;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class DeleteAccount extends BaseLivewireComponent
{
    public function form(Schema $schema): Schema
    {
        $hasPassword = $this->authUser()->hasPassword();

        return $schema
            ->schema([
                Section::make(__('profile.sections.delete_account.title'))
                    ->description(__('profile.sections.delete_account.description'))
                    ->aside()
                    ->schema([
                        TextEntry::make('deleteAccountNotice')
                            ->hiddenLabel()
                            ->state(fn (): string|array => __('profile.sections.delete_account.notice')),
                        Actions::make([
                            Action::make('deleteAccount')
                                ->label(__('profile.actions.delete_account'))
                                ->color('danger')
                                ->requiresConfirmation()
                                ->modalHeading(__('profile.sections.delete_account.title'))
                                ->modalDescription($hasPassword ? __('profile.modals.delete_account.notice') : __('profile.modals.delete_account.notice_no_password'))
                                ->modalSubmitActionLabel(__('profile.actions.delete_account'))
                                ->modalCancelAction(false)
                                ->schema($hasPassword ? [
                                    Forms\Components\TextInput::make('password')
                                        ->password()
                                        ->revealable()
                                        ->label(__('profile.form.password.label'))
                                        ->required()
                                        ->currentPassword(),
                                ] : [])
                                ->action(fn (array $data): Redirector|RedirectResponse|null => $this->deleteAccount($data['password'] ?? null)),
                        ]),
                    ]),
            ]);
    }

    /**
     * Schedule deletion for the current user.
     *
     * @throws ValidationException
     */
    public function deleteAccount(?string $password = null): Redirector|RedirectResponse|null
    {
        $user = $this->authUser();

        if ($user->hasPassword() && ! Hash::check((string) $password, $user->password ?? '')) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        try {
            resolve(ScheduleUserDeletion::class)->schedule($user);
        } catch (ValidationException $e) {
            Notification::make()
                ->danger()
                ->title(__('profile.notifications.delete_account_blocked.title'))
                ->body($e->validator->errors()->first())
                ->persistent()
                ->send();

            return null;
        }

        filament()->auth()->logout();

        return redirect(filament()->getLoginUrl());
    }

    public function render(): View
    {
        return view('livewire.app.profile.delete-account');
    }
}
