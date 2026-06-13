<?php

declare(strict_types=1);

namespace App\Livewire\App\Profile;

use App\Actions\Jetstream\ScheduleUserDeletion;
use App\Filament\Actions\ConfirmIdentityAction;
use App\Livewire\BaseLivewireComponent;
use App\Support\Auth\IdentityConfirmation;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class DeleteAccount extends BaseLivewireComponent
{
    public function form(Schema $schema): Schema
    {
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
                            $this->deleteAccountAction(),
                        ]),
                    ]),
            ]);
    }

    public function deleteAccountAction(): ConfirmIdentityAction
    {
        return ConfirmIdentityAction::make('deleteAccount')
            ->label(__('profile.actions.delete_account'))
            ->color('danger')
            ->alwaysConfirm()
            ->modalHeading(__('profile.sections.delete_account.title'))
            ->modalDescription(__('profile.modals.delete_account.notice'))
            ->modalSubmitActionLabel(__('profile.actions.delete_account'))
            ->modalCancelAction(false)
            ->confirmedUsing(fn (): Redirector|RedirectResponse|null => $this->deleteAccount());
    }

    public function deleteAccount(): Redirector|RedirectResponse|null
    {
        $user = $this->authUser();

        if (! IdentityConfirmation::satisfied($user)) {
            $this->notifyIdentityConfirmationFailed();

            return null;
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
