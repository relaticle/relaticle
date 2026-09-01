<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Jetstream\AcceptTeamInvitation;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Laravel\Jetstream\Jetstream;

/**
 * GET renders a confirm page and never mutates membership; POST is the only
 * action that joins. Invitations resolve solely by the opaque minted token.
 */
final readonly class AcceptTeamInvitationController
{
    public function show(Request $request, string $token): View|RedirectResponse
    {
        return $this->render($request, TeamInvitation::findByRawToken($token));
    }

    public function store(Request $request, string $token, AcceptTeamInvitation $acceptTeamInvitation): RedirectResponse|View
    {
        $invitation = TeamInvitation::findByRawToken($token);
        $user = $this->user($request);

        if (! $invitation instanceof TeamInvitation || $invitation->isExpired()) {
            return $this->render($request, $invitation);
        }

        if (! $this->emailMatches($user, $invitation)) {
            return $this->render($request, $invitation);
        }

        $team = $acceptTeamInvitation->execute($user, $invitation);

        return $this->redirectToTeam($team, __('teams.accept.joined', ['team' => $team->name]));
    }

    private function emailMatches(User $user, TeamInvitation $invitation): bool
    {
        return Str::lower($user->email) === Str::lower($invitation->email);
    }

    private function render(Request $request, ?TeamInvitation $invitation): View|RedirectResponse
    {
        $user = $this->user($request);

        if (! $invitation instanceof TeamInvitation || $invitation->isExpired()) {
            Log::warning('Invalid or expired invitation accessed', [
                'invitation_id' => $invitation?->id,
            ]);

            return view('teams.accept-invitation', ['state' => 'expired']);
        }

        if (! $this->emailMatches($user, $invitation)) {
            Log::warning('Invitation email mismatch', [
                'invitation_id' => $invitation->id,
                'user_id' => $user->id,
            ]);

            return view('teams.accept-invitation', [
                'state' => 'wrong-account',
                'invitedEmail' => $invitation->email,
                'currentEmail' => $user->email,
                'teamName' => $invitation->team->name,
                'switchUrl' => route('team-invitations.token.switch', ['token' => $request->route('token')]),
            ]);
        }

        if ($user->belongsToTeam($invitation->team)) {
            $user->switchTeam($invitation->team);

            return $this->redirectToTeam($invitation->team, __('teams.accept.already_member', ['team' => $invitation->team->name]));
        }

        return view('teams.accept-invitation', [
            'state' => 'ready',
            'teamName' => $invitation->team->name,
            'inviterName' => $invitation->inviter?->name,
            'roleName' => Jetstream::findRole($invitation->role)?->name,
            'joinUrl' => route('team-invitations.token.join', ['token' => $request->route('token')]),
        ]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    // getHomeUrl() resolves through the ambient tenant, not the request user, so
    // it must be primed when called from outside panel middleware.
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
