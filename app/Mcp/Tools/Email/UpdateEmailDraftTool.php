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
use Relaticle\EmailIntegration\Actions\PrepareAgentEmailDraft;
use Relaticle\EmailIntegration\Actions\SaveAgentEmailDraft;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;

#[Title('Update Email Draft')]
#[Description('Rewrite one of the current user\'s saved drafts. The arguments replace the draft\'s mailbox, recipients, subject and body, so pass everything the draft should keep. Attached files stay. Nothing is sent. Write `body` as plain text or markdown. The mailbox signature is added unless `include_signature` is false.')]
final class UpdateEmailDraftTool extends Tool
{
    use ChecksTokenAbility;
    use HasExplicitToolAnnotations;

    public function shouldRegister(): bool
    {
        return $this->holdsAnyEmailGrant(EmailGrant::Draft);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'draft_id' => $schema->string()->description('The draft ID, as returned by the create email draft tool or listed with status draft.')->required(),
            ...resolve(CreateEmailDraftTool::class)->schema($schema),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->required(),
            'status' => $schema->string()->required(),
            'mailbox' => $schema->string()->required(),
        ];
    }

    public function handle(Request $request, SaveAgentEmailDraft $saveDraft): Response|ResponseFactory
    {
        if (($denied = $this->denyIfTokenLacks(EmailGrant::Draft)) instanceof Response) {
            return $denied;
        }

        /** @var User $user */
        $user = auth()->user();

        /** @var array{draft_id: string, connected_account_id: string, to?: list<string>, cc?: list<string>, bcc?: list<string>, subject?: ?string, body?: ?string, include_signature?: bool, in_reply_to_email_id?: string} $validated */
        $validated = $request->validate(['draft_id' => ['required', 'string', 'max:64'], ...PrepareAgentEmailDraft::RULES]);

        $exists = Email::query()->composedBy($user)->where('status', EmailStatus::DRAFT)->whereKey($validated['draft_id'])->exists();

        if (! $exists) {
            return Response::error("Draft with ID [{$validated['draft_id']}] not found.");
        }

        $draft = $saveDraft->execute($user, $validated, EmailCreationSource::MCP, $validated['draft_id']);

        return Response::structured([
            'id' => (string) $draft->getKey(),
            'status' => $draft->status->value,
            'mailbox' => (string) $draft->connectedAccount->email_address,
        ]);
    }

    protected function idempotentHint(): bool
    {
        return true;
    }
}
