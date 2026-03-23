<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Models\PersonalAccessToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Get information about the authenticated user, current team, team members, and token abilities.')]
#[IsReadOnly]
#[IsIdempotent]
final class WhoAmiTool extends Tool
{
    use ChecksTokenAbility;

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $this->ensureTokenCan('read');

        /** @var User $user */
        $user = auth()->user();

        /** @var Team $team */
        $team = $user->currentTeam;

        $tokenAbilities = ['*'];
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken && $token->getKey()) {
            $tokenAbilities = $token->abilities;
        }

        $teamMembers = $team->allUsers()->map(fn (User $member): array => [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
        ])->values()->all();

        $result = [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
            ],
            'team_members' => $teamMembers,
            'token_abilities' => $tokenAbilities,
        ];

        return Response::text(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
