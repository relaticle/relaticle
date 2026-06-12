<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mcp;

use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController as BaseApproveAuthorizationController;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * MCP-aware approve handler. Validates that the user has selected exactly one
 * team they belong to, stashes the team_id in the session so the AuthCode
 * model's creating hook can persist it, then delegates to Passport's standard
 * approve flow.
 */
final class ApproveAuthorizationController extends BaseApproveAuthorizationController
{
    public function approve(Request $request, ResponseInterface $psrResponse): Response
    {
        $validated = $request->validate([
            'team_id' => ['required', 'string', 'size:26'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $team = Team::query()->find($validated['team_id']);

        abort_if(! $team instanceof Team, 422, 'Selected team does not exist.');
        abort_if(! $user->belongsToTeam($team), 403, 'You do not belong to the selected team.');

        $request->session()->put('mcp.oauth.team_id', $team->getKey());

        return parent::approve($request, $psrResponse);
    }
}
