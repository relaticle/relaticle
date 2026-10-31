<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Email;

use App\Mcp\Tools\Concerns\FindsOwnOutboundEmail;
use App\Mcp\Tools\Concerns\HasExplicitToolAnnotations;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Date;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Relaticle\EmailIntegration\Actions\RescheduleAgentEmail;
use RuntimeException;

#[Title('Reschedule Email')]
#[Description('Change when one of the current user\'s queued emails is sent. A time sooner than the hold is moved to the end of the hold, so the user can still cancel it.')]
final class RescheduleEmailTool extends Tool
{
    use FindsOwnOutboundEmail;
    use HasExplicitToolAnnotations;

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('The email ID, as returned by the send tools or the list emails tool.')->required(),
            'scheduled_for' => $schema->string()->description('ISO 8601 datetime to send at.')->required(),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->required(),
            'status' => $schema->string()->required(),
            'scheduled_for' => $schema->string()->nullable()->required(),
        ];
    }

    public function handle(Request $request, RescheduleAgentEmail $reschedule): Response|ResponseFactory
    {
        $email = $this->ownOutboundEmail($request);

        if ($email instanceof Response) {
            return $email;
        }

        /** @var array{scheduled_for: string} $validated */
        $validated = $request->validate(['scheduled_for' => ['required', 'date', 'after:now']]);

        try {
            return Response::structured($this->outboxState($reschedule->execute($email, Date::parse($validated['scheduled_for']))));
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }
    }

    protected function idempotentHint(): bool
    {
        return true;
    }
}
