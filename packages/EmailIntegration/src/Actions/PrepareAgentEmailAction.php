<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\WorkspaceCapability;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailPriority;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Queries\VisibleEmailsQuery;
use Relaticle\EmailIntegration\Support\AgentEmailBody;

final readonly class PrepareAgentEmailAction
{
    public const int MAX_RECIPIENTS = 20;

    public const string RECIPIENT_LIMIT_MESSAGE = 'An email can go to at most '.self::MAX_RECIPIENTS.' recipients in total across to, cc and bcc.';

    public function __construct(
        private AgentEmailBody $body,
        private VisibleEmailsQuery $emails,
    ) {}

    /**
     * @param  array{
     *     connected_account_id: string,
     *     to: list<string>,
     *     cc?: list<string>,
     *     bcc?: list<string>,
     *     subject: string,
     *     body: string,
     *     include_signature?: bool,
     *     in_reply_to_email_id?: ?string,
     * }  $data
     * @return array{
     *     connected_account_id: string,
     *     subject: string,
     *     body_html: string,
     *     to: list<array{email: string, name: null}>,
     *     cc: list<array{email: string, name: null}>,
     *     bcc: list<array{email: string, name: null}>,
     *     in_reply_to_email_id: ?string,
     *     creation_source: EmailCreationSource,
     *     batch_id: null,
     *     priority: EmailPriority,
     * }
     *
     * @throws ValidationException
     */
    public function execute(User $user, array $data, EmailCreationSource $source): array
    {
        $workspace = $user->currentWorkspace;

        abort_unless(
            $workspace instanceof Workspace
                && $user->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::EmailAgentSend),
            403,
        );

        $this->assertWithinRecipientLimit($data);

        $account = $this->sendableAccount($user, $workspace, $data['connected_account_id']);
        $replyTo = $this->replyTarget($user, $data['in_reply_to_email_id'] ?? null);

        return [
            'connected_account_id' => (string) $account->getKey(),
            'subject' => $data['subject'],
            'body_html' => $this->body->forSending($data['body'], $account, $data['include_signature'] ?? true),
            'to' => $this->recipients($data['to']),
            'cc' => $this->recipients($data['cc'] ?? []),
            'bcc' => $this->recipients($data['bcc'] ?? []),
            'in_reply_to_email_id' => $replyTo?->getKey(),
            'creation_source' => $source,
            'batch_id' => null,
            'priority' => EmailPriority::PRIORITY,
        ];
    }

    /** @param  array{to: list<string>, cc?: list<string>, bcc?: list<string>}  $data */
    private function assertWithinRecipientLimit(array $data): void
    {
        $total = count($data['to']) + count($data['cc'] ?? []) + count($data['bcc'] ?? []);

        if ($total > self::MAX_RECIPIENTS) {
            throw ValidationException::withMessages([
                'to' => self::RECIPIENT_LIMIT_MESSAGE,
            ]);
        }
    }

    private function sendableAccount(User $user, Workspace $workspace, string $accountId): ConnectedAccount
    {
        $account = ConnectedAccount::query()
            ->ownedBy($user, $workspace)
            ->whereKey($accountId)
            ->first();

        if (! $account instanceof ConnectedAccount || ! $account->isSendable()) {
            throw ValidationException::withMessages([
                'connected_account_id' => 'Pick one of your own mailboxes that can send. List your mailboxes to find one.',
            ]);
        }

        return $account;
    }

    private function replyTarget(User $user, ?string $replyToId): ?Email
    {
        if ($replyToId === null) {
            return null;
        }

        $replyTo = $this->emails->find($user, $replyToId);

        if (! $replyTo instanceof Email) {
            throw ValidationException::withMessages([
                'in_reply_to_email_id' => "Email with ID [{$replyToId}] not found.",
            ]);
        }

        return $replyTo;
    }

    /**
     * @param  list<string>  $addresses
     * @return list<array{email: string, name: null}>
     */
    private function recipients(array $addresses): array
    {
        return array_map(fn (string $address): array => ['email' => $address, 'name' => null], $addresses);
    }
}
