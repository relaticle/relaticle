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
use Relaticle\EmailIntegration\Actions\SaveEmailDraftAction;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Exceptions\EmptyDraft;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Queries\VisibleEmailsQuery;
use Relaticle\EmailIntegration\Support\AgentEmailBody;

#[Title('Create Email Draft')]
#[Description('Save an email draft in one of the current user\'s mailboxes. Nothing is sent: the user reviews and sends it from Drafts in Relaticle. Write `body` as plain text or markdown. The mailbox signature is added unless `include_signature` is false.')]
final class CreateEmailDraftTool extends Tool
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
            'connected_account_id' => $schema->string()->description('The mailbox to draft in, from the list email accounts tool.')->required(),
            'to' => $schema->array()->items($schema->string())->description('Recipient email addresses.'),
            'cc' => $schema->array()->items($schema->string())->description('CC email addresses.'),
            'bcc' => $schema->array()->items($schema->string())->description('BCC email addresses.'),
            'subject' => $schema->string()->description('Subject line, up to 255 characters.'),
            'body' => $schema->string()->description('The message as plain text or markdown. Raw HTML is escaped.'),
            'include_signature' => $schema->boolean()->description('Add the mailbox default signature.')->default(true),
            'in_reply_to_email_id' => $schema->string()->description('ID of the email this draft replies to.'),
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

    public function handle(Request $request, SaveEmailDraftAction $saveDraft, AgentEmailBody $body, VisibleEmailsQuery $emails): Response|ResponseFactory
    {
        if (($denied = $this->denyIfTokenLacks(EmailGrant::Draft)) instanceof Response) {
            return $denied;
        }

        /** @var User $user */
        $user = auth()->user();

        /** @var array{connected_account_id: string, to?: list<string>, cc?: list<string>, bcc?: list<string>, subject?: ?string, body?: ?string, include_signature?: bool, in_reply_to_email_id?: string} $validated */
        $validated = $request->validate($this->rules());

        $account = ConnectedAccount::query()
            ->ownedBy($user, $user->currentWorkspace)
            ->connected()
            ->whereKey($validated['connected_account_id'])
            ->first();

        if (! $account instanceof ConnectedAccount) {
            return Response::error("Mailbox with ID [{$validated['connected_account_id']}] not found.");
        }

        $replyTo = isset($validated['in_reply_to_email_id'])
            ? $emails->find($user, $validated['in_reply_to_email_id'])
            : null;

        if (isset($validated['in_reply_to_email_id']) && ! $replyTo instanceof Email) {
            return Response::error("Email with ID [{$validated['in_reply_to_email_id']}] not found.");
        }

        $threadsOnReply = filled($replyTo?->rfc_message_id);
        $markdown = (string) ($validated['body'] ?? '');
        $includeSignature = ($validated['include_signature'] ?? true) && trim($markdown) !== '';

        try {
            $draft = $saveDraft->execute($user, [
                'connected_account_id' => (string) $account->getKey(),
                'subject' => $validated['subject'] ?? null,
                'body_html' => $body->forDraft($markdown, $account, $includeSignature),
                'to' => $validated['to'] ?? [],
                'cc' => $validated['cc'] ?? [],
                'bcc' => $validated['bcc'] ?? [],
                'source_email_id' => $threadsOnReply ? $replyTo->getKey() : null,
                'creation_source' => $threadsOnReply ? EmailCreationSource::REPLY : EmailCreationSource::MCP,
            ]);
        } catch (EmptyDraft $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::structured([
            'id' => (string) $draft->getKey(),
            'status' => $draft->status->value,
            'mailbox' => (string) $account->email_address,
        ]);
    }

    protected function destructiveHint(): bool
    {
        return false;
    }

    /** @return array<string, list<string>> */
    private function rules(): array
    {
        return [
            'connected_account_id' => ['required', 'string', 'max:64'],
            'to' => ['sometimes', 'array', 'list', 'max:20'],
            'to.*' => ['required', 'string', 'email', 'max:255'],
            'cc' => ['sometimes', 'array', 'list', 'max:20'],
            'cc.*' => ['required', 'string', 'email', 'max:255'],
            'bcc' => ['sometimes', 'array', 'list', 'max:20'],
            'bcc.*' => ['required', 'string', 'email', 'max:255'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string', 'max:50000'],
            'include_signature' => ['sometimes', 'boolean'],
            'in_reply_to_email_id' => ['sometimes', 'string', 'max:64'],
        ];
    }
}
