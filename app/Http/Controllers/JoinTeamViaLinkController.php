<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Jetstream\Contracts\AddsTeamMembers;

final readonly class JoinTeamViaLinkController
{
    public function show(Request $request, string $token): RedirectResponse|View
    {
        $team = $this->resolveTeam($token);

        if ($team instanceof View) {
            return $team;
        }

        /** @var User $user */
        $user = $request->user();

        if ($user->belongsToTeam($team)) {
            $user->switchTeam($team);

            return $this->redirectToTeam($team, __('teams.accept.already_member', ['team' => $team->name]));
        }

        return view('teams.join-via-link', ['team' => $team, 'token' => $token]);
    }

    public function store(Request $request, string $token, AddsTeamMembers $adder): RedirectResponse|View
    {
        $team = $this->resolveTeam($token);

        if ($team instanceof View) {
            return $team;
        }

        /** @var User $user */
        $user = $request->user();

        if ($user->belongsToTeam($team)) {
            $user->switchTeam($team);

            return $this->redirectToTeam($team, __('teams.accept.already_member', ['team' => $team->name]));
        }

        /** @var User $owner */
        $owner = $team->owner;

        $adder->add(
            $owner,
            $team,
            $user->email,
            $team->invite_link_default_role,
        );

        $user->unsetRelation('teams');
        $user->switchTeam($team);

        return $this->redirectToTeam($team, __('teams.accept.joined', ['team' => $team->name]));
    }

    private function resolveTeam(string $token): Team|View
    {
        $team = Team::query()
            ->where('invite_link_token', $token)
            ->firstOrFail();

        if ($team->isInviteLinkTokenExpired()) {
            return view('teams.invite-link-expired');
        }

        abort_if($team->isScheduledForDeletion(), 410, __('This team is scheduled for deletion and is not accepting new members.'));

        $user = request()->user();

        abort_if($user instanceof User && $user->isScheduledForDeletion(), 403, __('You cannot join teams while your account is scheduled for deletion.'));

        return $team;
    }

    /**
     * Filament's own home-URL closure (see AppPanelProvider) resolves the
     * dashboard through the ambient Filament::getTenant(), not the request's
     * authenticated user, so it must be primed before getHomeUrl() is called
     * from outside panel middleware. isQuiet skips SwitchTeam, which already
     * ran (or is irrelevant) by this point.
     */
    private function redirectToTeam(Team $team, string $message): RedirectResponse
    {
        Filament::setTenant($team, isQuiet: true);

        Notification::make()
            ->title($message)
            ->success()
            ->send();

        return redirect(Filament::getHomeUrl() ?? url()->getAppUrl());
    }
}
