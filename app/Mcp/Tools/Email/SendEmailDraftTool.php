<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Email;

use App\Enums\EmailGrant;
use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Mcp\Tools\Concerns\HasExplicitToolAnnotations;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Relaticle\EmailIntegration\Actions\SendAgentEmailDraft;
use Relaticle\EmailIntegration\Exceptions\AgentOutboxFull;
use Relaticle\EmailIntegration\Exceptions\OutboxFull;

#[Title('Send Email Draft')]
#[Description('Send one of the current user\'s saved drafts exactly as it stands, including any changes the user made in Relaticle, and remove it from Drafts. The email reaches people outside the workspace, so call this only when the user asked for it. It is held for a few minutes first, and the user can cancel it from their Relaticle notifications or Outbox.')]
final class SendEmailDraftTool extends Tool
{
    use ChecksTokenAbility;
    use HasExplicitToolAnnotations;

    public function shouldRegister(): bool
    {
        return $this->holdsAnyEmailGrant(EmailGrant::Send) && $this->roleAllowsAgentSend();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'draft_id' => $schema->string()->description('The draft ID, as returned by the create email draft tool or listed with status draft.')->required(),
            'scheduled_for' => $schema->string()->description('ISO 8601 datetime to send at. A time sooner than the hold is moved to the end of the hold.'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->required(),
            'status' => $schema->string()->required(),
            'scheduled_for' => $schema->string()->required(),
        ];
    }

    public function handle(Request $request, SendAgentEmailDraft $sendDraft): Response|ResponseFactory
    {
        if (($denied = $this->denyIfTokenLacks(EmailGrant::Send)) instanceof Response) {
            return $denied;
        }

        if (! $this->roleAllowsAgentSend()) {
            return Response::error('Your role in this workspace cannot send email through an assistant.');
        }

        /** @var User $user */
        $user = auth()->user();

        /** @var array{draft_id: string, scheduled_for?: string} $validated */
        $validated = $request->validate([
            'draft_id' => ['required', 'string', 'max:64'],
            'scheduled_for' => ['sometimes', 'date', 'after:now'],
        ]);

        try {
            $email = $sendDraft->execute($user, $validated['draft_id'], $validated['scheduled_for'] ?? null, $this->connectionName());
        } catch (OutboxFull|AgentOutboxFull $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::structured([
            'id' => (string) $email->getKey(),
            'status' => $email->status->value,
            'scheduled_for' => (string) $email->scheduled_for?->toIso8601String(),
        ]);
    }

    protected function openWorldHint(): bool
    {
        return true;
    }
}
