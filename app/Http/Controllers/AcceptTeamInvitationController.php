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
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Laravel\Jetstream\Jetstream;

/**
 * GET always renders a confirm page and never mutates membership; POST is the
 * only action that joins. Two invitation shapes resolve here: the opaque
 * token minted by `TeamInvitation::issueToken()` (Task 4) for new invitations,
 * and the legacy signed-URL-over-ULID shape for rows created before it. Every
 * downstream decision (expiry, email match, states) is shared between them.
 */
final readonly class AcceptTeamInvitationController
{
    public function show(Request $request, string $token): View|RedirectResponse
    {
        return $this->render($request, $this->resolve($token, $request));
    }

    public function store(Request $request, string $token, AcceptTeamInvitation $acceptTeamInvitation): RedirectResponse|View
    {
        $invitation = $this->resolve($token, $request);
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

    private function resolve(string $token, Request $request): ?TeamInvitation
    {
        if ($request->routeIs('team-invitations.token.*')) {
            return TeamInvitation::findByRawToken($token);
        }

        return TeamInvitation::query()->whereKey($token)->first();
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
            'joinUrl' => $request->routeIs('team-invitations.token.*')
                ? route('team-invitations.token.join', ['token' => $request->route('token')])
                : URL::signedRoute('team-invitations.join', ['invitation' => $invitation]),
        ]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
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
