<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Laravel\Jetstream\Contracts\AddsTeamMembers;

final readonly class AcceptTeamInvitationController
{
    public function __invoke(Request $request, string $invitationId): RedirectResponse|View
    {
        $invitation = TeamInvitation::query()->whereKey($invitationId)->firstOrFail();

        if ($invitation->isExpired()) {
            Log::warning('Expired invitation accessed', [
                'invitation_id' => $invitation->id,
                'team_id' => $invitation->team_id,
            ]);

            return view('teams.invitation-expired');
        }

        /** @var User $user */
        $user = $request->user();

        if ($user->email !== $invitation->email) {
            Log::warning('Invitation email mismatch', [
                'invitation_id' => $invitation->id,
                'user_id' => $user->id,
            ]);

            abort(403, __('This invitation was sent to a different email address.'));
        }

        abort_if($user->isScheduledForDeletion(), 403, __('You cannot accept team invitations while your account is scheduled for deletion.'));

        /** @var User $owner */
        $owner = $invitation->team->owner;

        resolve(AddsTeamMembers::class)->add(
            $owner,
            $invitation->team,
            $invitation->email,
            $invitation->role,
        );

        $invitation->delete();

        $user->switchTeam($invitation->team);

        return redirect(config('fortify.home'))
            ->banner(__('Great! You have accepted the invitation to join the :team team.', [ // @phpstan-ignore method.notFound
                'team' => $invitation->team->name,
            ]));
    }
}
