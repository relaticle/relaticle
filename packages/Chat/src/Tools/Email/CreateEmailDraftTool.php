<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Email;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\Concerns\NormalizesToolInput;
use Relaticle\Chat\Tools\Concerns\ReportsValidationFailures;
use Relaticle\EmailIntegration\Actions\SaveAgentEmailDraft;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailPageTab;
use Relaticle\EmailIntegration\Exceptions\EmptyDraft;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;

final readonly class CreateEmailDraftTool implements Tool
{
    use NormalizesToolInput;
    use ReportsValidationFailures;

    public function __construct(private SaveAgentEmailDraft $saveDraft) {}

    public function description(): string
    {
        return 'Save an email draft in one of the user\'s mailboxes. Nothing is sent: the user reviews the draft and sends it from Drafts.'
            .' Write `body` as plain text or markdown. The mailbox signature is added unless `include_signature` is false.';
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
            'in_reply_to_email_id' => $schema->string()->description('ID of the email this draft replies to, so it threads.'),
        ];
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();

        try {
            /** @var array{connected_account_id: string, to?: list<string>, cc?: list<string>, bcc?: list<string>, subject?: ?string, body?: ?string, include_signature?: bool, in_reply_to_email_id?: string} $validated */
            $validated = $this->withoutNullArguments($request)->validate(SaveAgentEmailDraft::RULES);

            $draft = $this->saveDraft->execute($user, $validated, EmailCreationSource::CHAT);
        } catch (ValidationException $exception) {
            return $this->validationError($exception);
        } catch (EmptyDraft $exception) {
            return (string) json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_SLASHES);
        }

        return (string) json_encode([
            'id' => (string) $draft->getKey(),
            'mailbox' => (string) $draft->connectedAccount->email_address,
            'url' => EmailInboxPage::getUrl(['tab' => EmailPageTab::DRAFTS->value], panel: 'app', tenant: $user->currentWorkspace),
            'note' => 'Saved to Drafts. Nothing was sent. Tell the user to review it and send it from Drafts.',
        ], JSON_UNESCAPED_SLASHES);
    }
}
