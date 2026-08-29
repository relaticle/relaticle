<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Mcp\Tools\Concerns\HasReadOnlyToolAnnotations;
use App\Models\PersonalAccessToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Title('Get Account Context')]
#[Description('Get information about the authenticated user, current team, team members, and token abilities.')]
final class WhoAmiTool extends Tool
{
    use ChecksTokenAbility;
    use HasReadOnlyToolAnnotations;

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'user' => $schema->object()->required(),
            'team' => $schema->object()->required(),
            'team_members' => $schema->array()->items($schema->object())->required(),
            'token_abilities' => $schema->array()->items($schema->string())->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($denied = $this->denyIfTokenCannot('read')) instanceof Response) {
            return $denied;
        }

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

        return Response::structured($result);
    }
}
