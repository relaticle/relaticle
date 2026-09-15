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
            .'from a file; exporting records to a file; or managing workspace members; creating or revoking API '
            .'access tokens and connectors; or connecting Claude, ChatGPT or another MCP client to the workspace. '
            .'Call this instead of telling the user something is impossible.';
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
                    .'"export_companies", "export_people", "export_opportunities", "export_tasks", "export_notes" '
                    .'(export records of that type to a CSV or XLSX file); '
                    .'"workspace_members" (invite or manage workspace members); '
                    .'"access_tokens" (create or revoke API access tokens and connectors); '
                    .'"connect_assistant" (the help page for connecting Claude, ChatGPT or another MCP client).',
                ),
        ];
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();
        $workspace = $user->currentWorkspace;

        $destination = (string) ($request['destination'] ?? '');

        $url = $workspace === null ? null : $this->destinations->resolve($destination, $workspace);

        if ($url === null) {
            return (string) json_encode([
                'error' => "No page is available for destination [{$destination}].",
            ], JSON_UNESCAPED_SLASHES);
        }

        return (string) json_encode([
            'type' => 'navigation',
            'destination' => $destination,
            'url' => $url,
        ], JSON_UNESCAPED_SLASHES);
    }
}
