<?php

declare(strict_types=1);

namespace App\Livewire\App\Profile;

use App\Livewire\BaseLivewireComponent;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Agent;

final class LogoutOtherBrowserSessions extends BaseLivewireComponent
{
    public function form(Schema $schema): Schema
    {
        $hasPassword = $this->authUser()->hasPassword();

        return $schema
            ->schema([
                Section::make(__('profile.sections.browser_sessions.title'))
                    ->description(__('profile.sections.browser_sessions.description'))
                    ->aside()
                    ->schema([
                        Forms\Components\ViewField::make('browserSessions')
                            ->hiddenLabel()
                            ->view('components.browser-sessions')
                            ->viewData(['sessions' => $this->browserSessions()]),
                        Actions::make([
                            Action::make('deleteBrowserSessions')
                                ->label(__('profile.actions.log_out_other_browsers'))
                                ->requiresConfirmation()
                                ->modalHeading(__('profile.modals.log_out_other_browsers.title'))
                                ->modalDescription($hasPassword ? __('profile.modals.log_out_other_browsers.description') : __('profile.modals.log_out_other_browsers.description_no_password'))
                                ->modalSubmitActionLabel(__('profile.actions.log_out_other_browsers'))
                                ->modalCancelAction(false)
                                ->schema($hasPassword ? [
                                    Forms\Components\TextInput::make('password')
                                        ->password()
                                        ->revealable()
                                        ->label(__('profile.form.password.label'))
                                        ->required()
                                        ->currentPassword(),
                                ] : [])
                                ->action(
                                    fn (array $data) => $this->logoutOtherBrowserSessions($data['password'] ?? null)
                                ),
                        ]),
                    ]),
            ]);
    }

    /**
     * @throws ValidationException
     */
    public function logoutOtherBrowserSessions(?string $password): void
    {
        $user = $this->authUser();

        if ($user->hasPassword() && ! Hash::check((string) $password, $user->password ?? '')) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        if (config('session.driver') !== 'database') {
            return;
        }

        if ($password !== null) {
            auth(filament()->getAuthGuard())->logoutOtherDevices($password);
        }

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', filament()->auth()->user()->getAuthIdentifier())
            ->where('id', '!=', request()->session()->getId())
            ->delete();

        request()
            ->session()
            ->put([
                'password_hash_'.Auth::getDefaultDriver() => filament()->auth()->user()->getAuthPassword(),
            ]);

        $this->sendNotification(__('profile.notifications.logged_out_other_sessions.success'));
    }

    /**
     * Get the current sessions.
     *
     * @return Collection<int, object{agent: Agent, ip_address: mixed, is_current_device: bool, last_active: string}>
     */
    public function browserSessions(): Collection
    {
        if (config('session.driver') !== 'database') {
            return collect();
        }

        // @phpstan-ignore-next-line Collection type covariance issue
        return DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
            ->where('user_id', filament()->auth()->user()->getAuthIdentifier())
            ->orderBy('last_activity', 'desc')
            ->get()->map(function (\stdClass $session): object {
                $agent = tap(new Agent, function (Agent $agent) use ($session): void {
                    $agent->setUserAgent($session->user_agent ?? '');
                });

                return (object) [
                    'agent' => $agent,
                    'ip_address' => $session->ip_address,
                    'is_current_device' => $session->id === request()->session()->getId(),
                    'last_active' => Date::createFromTimestamp($session->last_activity)->diffForHumans(),
                ];
            });
    }

    public function render(): View
    {
        return view('livewire.app.profile.logout-other-browser-sessions');
    }
}
