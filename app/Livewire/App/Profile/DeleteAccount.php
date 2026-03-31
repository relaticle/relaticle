<?php

declare(strict_types=1);

namespace App\Livewire\App\Profile;

use App\Actions\Jetstream\DeleteTeam;
use App\Livewire\BaseLivewireComponent;
use App\Models\Team;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
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
                                ->action(fn (array $data): Redirector|RedirectResponse => $this->deleteAccount($data['password'] ?? null)),
                        ]),
                    ]),
            ]);
    }

    /**
     * Delete the current user.
     *
     * @throws ValidationException
     */
    public function deleteAccount(?string $password = null): Redirector|RedirectResponse
    {
        $user = $this->authUser();

        if ($user->hasPassword() && ! Hash::check((string) $password, $user->password ?? '')) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        // Logout before deleting to prevent SessionGuard::logout() from
        // re-inserting the user when it updates the remember token.
        filament()->auth()->logout();

        DB::transaction(function () use ($user): void {
            if (config('jetstream.features.teams', false)) {
                $user->teams()->detach();

                /** @var Collection<int, Team> $ownedTeams */
                $ownedTeams = $user->ownedTeams;
                $ownedTeams->each(function (Team $team): void {
                    resolve(DeleteTeam::class)->delete($team);
                });
            }

            $user->deleteProfilePhoto();
            $user->tokens->each->delete();

            $user->delete();
        });

        return redirect(filament()->getLoginUrl());
    }

    public function render(): View
    {
        return view('livewire.app.profile.delete-account');
    }
}
