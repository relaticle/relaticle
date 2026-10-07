<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Email;

use App\Enums\EmailGrant;
use App\Enums\WorkspaceCapability;
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
use Relaticle\EmailIntegration\Actions\PrepareAgentEmail;
use Relaticle\EmailIntegration\Actions\QueueAgentEmailAction;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Exceptions\AgentOutboxFull;
use Relaticle\EmailIntegration\Exceptions\OutboxFull;

#[Title('Send Email')]
#[Description('Send an email as the current user from one of their mailboxes. The email reaches people outside the workspace, so call this only when the user asked for it. It is held for a few minutes first, and the user can cancel it from their Relaticle notifications or Outbox. Write `body` as plain text or markdown. Prefer the create email draft tool when the user wants to review before sending.')]
final class SendEmailTool extends Tool
{
    use ChecksTokenAbility;
    use HasExplicitToolAnnotations;

    public function shouldRegister(): bool
    {
        return $this->holdsAnyEmailGrant(EmailGrant::Send) && $this->roleAllowsSending();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'connected_account_id' => $schema->string()->description('The mailbox to send from, from the list email accounts tool. It must have can_send true.')->required(),
            'to' => $schema->array()->items($schema->string())->description('Recipient email addresses. At most '.PrepareAgentEmail::MAX_RECIPIENTS.' recipients in total across to, cc and bcc.')->required(),
            'cc' => $schema->array()->items($schema->string())->description('CC email addresses.'),
            'bcc' => $schema->array()->items($schema->string())->description('BCC email addresses.'),
            'subject' => $schema->string()->description('Subject line, up to 255 characters.')->required(),
            'body' => $schema->string()->description('The message as plain text or markdown. Raw HTML is escaped.')->required(),
            'include_signature' => $schema->boolean()->description('Add the mailbox default signature.')->default(true),
            'in_reply_to_email_id' => $schema->string()->description('ID of the email this replies to, so it threads.'),
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

    public function handle(Request $request, QueueAgentEmailAction $queueEmail): Response|ResponseFactory
    {
        if (($denied = $this->denyIfTokenLacks(EmailGrant::Send)) instanceof Response) {
            return $denied;
        }

        if (! $this->roleAllowsSending()) {
            return Response::error('Your role in this workspace cannot send email through an assistant.');
        }

        /** @var User $user */
        $user = auth()->user();

        /** @var array{connected_account_id: string, to: list<string>, cc?: list<string>, bcc?: list<string>, subject: string, body: string, include_signature?: bool|int|string, in_reply_to_email_id?: string} $validated */
        $validated = $request->validate(PrepareAgentEmail::RULES, [
            'to.max' => PrepareAgentEmail::RECIPIENT_LIMIT_MESSAGE,
            'cc.max' => PrepareAgentEmail::RECIPIENT_LIMIT_MESSAGE,
            'bcc.max' => PrepareAgentEmail::RECIPIENT_LIMIT_MESSAGE,
        ]);

        if (array_key_exists('include_signature', $validated)) {
            $validated['include_signature'] = (bool) $validated['include_signature'];
        }

        try {
            $email = $queueEmail->execute($user, $validated, EmailCreationSource::MCP, $this->connectionName());
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

    private function roleAllowsSending(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->hasWorkspaceCapability($user->currentWorkspace?->getKey(), WorkspaceCapability::EmailAgentSend);
    }
}
