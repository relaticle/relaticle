<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Support\TeamMembersContext;

final class ListTeamMembersTool implements Tool
{
    public function description(): string
    {
        return 'List workspace team members (users). Team members are the ONLY valid values'
            .' for team-member fields — a company\'s account_owner_id and a task\'s assignee_ids.'
            .' Contacts/people records are NOT valid for those fields. Call this to resolve a'
            .' member name to their user id before proposing such an update.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Optional name or email filter.'),
        ];
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();

        $search = $request['search'] ?? null;

        $members = TeamMembersContext::for($user, is_string($search) && $search !== '' ? $search : null);

        return (string) json_encode([
            'members' => $members,
            'note' => 'Use the id silently for account_owner_id / assignee_ids; never display ids to the user.',
        ], JSON_PRETTY_PRINT);
    }
}
