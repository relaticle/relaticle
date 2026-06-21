<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Support\DestinationResolver;

final readonly class GuideToPageTool implements Tool
{
    public function __construct(private DestinationResolver $destinations) {}

    public function description(): string
    {
        return 'Get a direct link to the workspace page where the user can perform an action this assistant '
            .'cannot do itself: creating, editing, or deleting custom field definitions; bulk-importing records '
            .'from a file; or managing team members. Call this instead of telling the user something is impossible.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'destination' => $schema->string()
                ->required()
                ->description(
                    'Where to send the user. One of: '
                    .'"custom_fields" (create/edit/delete custom field definitions); '
                    .'"import_companies", "import_people", "import_opportunities", "import_tasks", "import_notes" '
                    .'(bulk-import many records of that type from a file); '
                    .'"team_members" (invite or manage team members).',
                ),
        ];
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();
        $team = $user->currentTeam;

        $destination = (string) ($request['destination'] ?? '');

        $url = $team === null ? null : $this->destinations->resolve($destination, $team);

        if ($url === null) {
            return (string) json_encode([
                'error' => "No page is available for destination [{$destination}].",
            ]);
        }

        return (string) json_encode([
            'type' => 'navigation',
            'destination' => $destination,
            'url' => $url,
        ], JSON_PRETTY_PRINT);
    }
}
