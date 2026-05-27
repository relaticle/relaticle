<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
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

            return redirect(config('fortify.home'))
                ->banner(__('You are already a member of :team.', ['team' => $team->name])); // @phpstan-ignore method.notFound
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

            return redirect(config('fortify.home'))
                ->banner(__('You are already a member of :team.', ['team' => $team->name])); // @phpstan-ignore method.notFound
        }

        /** @var User $owner */
        $owner = $team->owner;

        $adder->add(
            $owner,
            $team,
            $user->email,
            TeamRole::Editor->value,
        );

        $user->unsetRelation('teams');
        $user->switchTeam($team);

        return redirect(config('fortify.home'))
            ->banner(__('You have joined the :team team.', ['team' => $team->name])); // @phpstan-ignore method.notFound
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
}
