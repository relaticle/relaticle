<?php

declare(strict_types=1);

namespace App\Livewire\App\Profile;

use App\Actions\Jetstream\CancelUserDeletion;
use App\Livewire\BaseLivewireComponent;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\View\View;
use Livewire\Attributes\Layout;

#[Layout('layouts.filament-standalone')]
final class ScheduledDeletionInterstitial extends BaseLivewireComponent
{
    public function mount(): Redirector|RedirectResponse|null
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->isScheduledForDeletion()) {
            $tenant = Filament::getTenant() ?? ($user instanceof User ? $user->currentTeam : null);

            if ($tenant) {
                Filament::setTenant($tenant);

                return redirect(Filament::getHomeUrl());
            }

            return redirect(Filament::getLoginUrl());
        }

        return null;
    }

    public function cancelDeletion(): Redirector|RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();

        resolve(CancelUserDeletion::class)->cancel($user);

        $this->sendNotification('Account deletion cancelled');

        $tenant = Filament::getTenant() ?? $user->currentTeam;

        if ($tenant) {
            Filament::setTenant($tenant);

            return redirect(Filament::getHomeUrl());
        }

        return redirect(Filament::getLoginUrl());
    }

    public function logout(): Redirector|RedirectResponse
    {
        filament()->auth()->logout();

        return redirect(Filament::getLoginUrl());
    }

    public function cancelDeletionAction(): Action
    {
        return Action::make('cancelDeletion')
            ->label(__('profile.scheduled_deletion_interstitial.actions.cancel_deletion.label'))
            ->color('primary')
            ->extraAttributes(['class' => 'w-full justify-center'])
            ->requiresConfirmation()
            ->modalHeading(__('profile.scheduled_deletion_interstitial.actions.cancel_deletion.modal_heading'))
            ->modalDescription(__('profile.scheduled_deletion_interstitial.actions.cancel_deletion.modal_description'))
            ->modalSubmitActionLabel(__('profile.scheduled_deletion_interstitial.actions.cancel_deletion.modal_submit_label'))
            ->action(fn (): Redirector|RedirectResponse => $this->cancelDeletion());
    }

    public function logoutAction(): Action
    {
        return Action::make('logout')
            ->label(__('profile.scheduled_deletion_interstitial.actions.logout.label'))
            ->color('gray')
            ->link()
            ->action(fn (): Redirector|RedirectResponse => $this->logout());
    }

    public function render(): View
    {
        return view('livewire.app.profile.scheduled-deletion-interstitial');
    }
}
