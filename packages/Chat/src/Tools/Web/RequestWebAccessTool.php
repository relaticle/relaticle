<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Web;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final class RequestWebAccessTool implements Tool
{
    public function description(): string
    {
        return 'Ask the user to let you search the web and read web pages on this turn. '
            .'Call it only when answering needs the web (a URL, a website, or something online): once, before any web lookup and before any write for that request, with the search you plan, then stop. The user allows or declines it on a card. '
            .'Never call it for a request the workspace data can answer or for a write that needs nothing from the web.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('The web search you plan to run, e.g. "filaforms.com company overview".')->required(),
            'reason' => $schema->string()->description('One short sentence telling the user why the web helps with their request.')->required(),
        ];
    }

    public function handle(Request $request): string
    {
        return (string) json_encode(['error' => __('Web access requests are not available yet.')], JSON_UNESCAPED_SLASHES);
    }
}
