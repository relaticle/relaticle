<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Email;

use App\Mcp\Tools\Concerns\FindsOwnOutboundEmail;
use App\Mcp\Tools\Concerns\HasExplicitToolAnnotations;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Relaticle\EmailIntegration\Actions\RequeueAgentEmail;
use RuntimeException;

#[Title('Retry Email')]
#[Description('Queue one of the current user\'s failed emails again. It is held for a few minutes first, like a new send, and the user can cancel it from their Relaticle notifications or Outbox. Only an email whose `status` is failed can be retried.')]
final class RetryEmailTool extends Tool
{
    use FindsOwnOutboundEmail;
    use HasExplicitToolAnnotations;

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('The email ID, as returned by the send tools or the list emails tool.')->required(),
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

    public function handle(Request $request, RequeueAgentEmail $requeue): Response|ResponseFactory
    {
        $email = $this->ownOutboundEmail($request);

        if ($email instanceof Response) {
            return $email;
        }

        /** @var User $user */
        $user = auth()->user();

        try {
            return Response::structured($this->outboxState($requeue->execute($user, $email, $this->connectionName())));
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }
    }

    protected function openWorldHint(): bool
    {
        return true;
    }
}
