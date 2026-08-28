<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Actions\Crm\GetCrmSummary;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Description('CRM summary with record counts, pipeline breakdown by stage, and task status. Use for overview and analytics questions.')]
#[Uri('relaticle://summary/crm')]
#[MimeType('application/json')]
final class CrmSummaryResource extends Resource
{
    public function __construct(
        private readonly GetCrmSummary $summary,
    ) {}

    public function shouldRegister(): bool
    {
        $token = auth()->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || ! $token->getKey()) {
            return true;
        }

        return $token->can('read');
    }

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Response::text(json_encode($this->summary->execute($user), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
