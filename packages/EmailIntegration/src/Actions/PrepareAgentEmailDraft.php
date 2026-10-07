<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Queries\VisibleEmailsQuery;
use Relaticle\EmailIntegration\Support\AgentEmailBody;

final readonly class PrepareAgentEmailDraft
{
    public const array RULES = [
        'connected_account_id' => ['required', 'string', 'max:64'],
        'to' => ['sometimes', 'array', 'list', 'max:'.PrepareAgentEmail::MAX_RECIPIENTS],
        'to.*' => ['required', 'string', 'email', 'max:255'],
        'cc' => ['sometimes', 'array', 'list', 'max:'.PrepareAgentEmail::MAX_RECIPIENTS],
        'cc.*' => ['required', 'string', 'email', 'max:255'],
        'bcc' => ['sometimes', 'array', 'list', 'max:'.PrepareAgentEmail::MAX_RECIPIENTS],
        'bcc.*' => ['required', 'string', 'email', 'max:255'],
        'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
        'body' => ['sometimes', 'nullable', 'string', 'max:50000'],
        'include_signature' => ['sometimes', 'boolean'],
        'in_reply_to_email_id' => ['sometimes', 'string', 'max:64'],
    ];

    public function __construct(
        private AgentEmailBody $body,
        private VisibleEmailsQuery $emails,
    ) {}

    /**
     * @param  array{
     *     connected_account_id: string,
     *     to?: list<string>,
     *     cc?: list<string>,
     *     bcc?: list<string>,
     *     subject?: ?string,
     *     body?: ?string,
     *     include_signature?: bool,
     *     in_reply_to_email_id?: ?string,
     * }  $data
     * @return array{
     *     connected_account_id: string,
     *     subject: ?string,
     *     body_html: string,
     *     to: list<string>,
     *     cc: list<string>,
     *     bcc: list<string>,
     *     source_email_id: ?string,
     *     creation_source: EmailCreationSource,
     * }
     *
     * @throws ValidationException
     */
    public function execute(User $user, array $data, EmailCreationSource $source): array
    {
        $account = $this->connectedAccount($user, $data['connected_account_id']);
        $replyTo = $this->emails->replyTarget($user, $data['in_reply_to_email_id'] ?? null);

        $threadsOnReply = $replyTo instanceof Email && filled($replyTo->rfc_message_id);
        $markdown = (string) ($data['body'] ?? '');
        $includeSignature = ($data['include_signature'] ?? true) && trim($markdown) !== '';

        return [
            'connected_account_id' => (string) $account->getKey(),
            'subject' => $data['subject'] ?? null,
            'body_html' => $this->body->forDraft($markdown, $account, $includeSignature),
            'to' => $data['to'] ?? [],
            'cc' => $data['cc'] ?? [],
            'bcc' => $data['bcc'] ?? [],
            'source_email_id' => $threadsOnReply ? $replyTo->getKey() : null,
            'creation_source' => $threadsOnReply ? EmailCreationSource::REPLY : $source,
        ];
    }

    private function connectedAccount(User $user, string $accountId): ConnectedAccount
    {
        $account = ConnectedAccount::query()
            ->ownedBy($user, $user->currentWorkspace)
            ->connected()
            ->whereKey($accountId)
            ->first();

        if (! $account instanceof ConnectedAccount) {
            throw ValidationException::withMessages([
                'connected_account_id' => "Mailbox with ID [{$accountId}] not found.",
            ]);
        }

        return $account;
    }
}
