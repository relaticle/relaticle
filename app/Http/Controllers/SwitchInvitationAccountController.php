<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Signs the wrong account out and returns to the invitation rather than to the
 * marketing home, so the invitee keeps the link instead of losing it with the session.
 */
final readonly class SwitchInvitationAccountController
{
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('team-invitations.token.accept', ['token' => $token]);
    }
}
