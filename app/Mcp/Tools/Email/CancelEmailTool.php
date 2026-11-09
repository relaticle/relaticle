<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Email;

use App\Mcp\Tools\Concerns\FindsOwnOutboundEmail;
use App\Mcp\Tools\Concerns\HasExplicitToolAnnotations;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Relaticle\EmailIntegration\Actions\CancelQueuedEmailAction;
use RuntimeException;

#[Title('Cancel Email')]
#[Description('Cancel one of the current user\'s queued emails so it is never sent. Only an email whose `status` is queued can be cancelled; once it is sending, it is too late.')]
final class CancelEmailTool extends Tool
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

    public function handle(Request $request, CancelQueuedEmailAction $cancel): Response|ResponseFactory
    {
        $email = $this->ownOutboundEmail($request);

        if ($email instanceof Response) {
            return $email;
        }

        try {
            return Response::structured($this->outboxState($cancel->execute($email)));
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }
    }
}
