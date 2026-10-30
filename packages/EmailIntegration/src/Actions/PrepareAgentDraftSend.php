<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\WorkspaceCapability;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailPriority;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;
use Relaticle\EmailIntegration\Services\EmailTemplateRenderService;
use Relaticle\EmailIntegration\Services\ForwardAttachmentCopyService;

final readonly class PrepareAgentDraftSend
{
    public function __construct(
        private EmailTemplateRenderService $renderer,
        private ForwardAttachmentCopyService $attachments,
    ) {}

    /**
     * Run inside the caller's transaction: the draft row stays locked until it is deleted,
     * so a second send of the same draft finds nothing.
     *
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
     *     scheduled_for: ?CarbonImmutable,
     *     attachments: list<string>,
     *     attachment_file_names: array<string, string>,
     *     attachment_attributes: array<string, array{is_inline: bool, content_id: ?string}>,
     * }
     *
     * @throws ValidationException
     */
    public function execute(User $user, string $draftId, EmailCreationSource $source, ?string $scheduledFor = null): array
    {
        abort_unless(
            $user->currentWorkspace instanceof Workspace
                && $user->hasWorkspaceCapability($user->currentWorkspace->getKey(), WorkspaceCapability::EmailAgentSend),
            403,
        );

        $draft = $this->lockedDraft($user, $draftId);
        $recipients = $this->recipients($draft);

        $this->assertSendable($user, $draft, $recipients);

        [$paths, $names, $attributes, $unavailable] = $this->attachments->copyRecords($user, $draft->attachments);

        if ($unavailable !== []) {
            $this->attachments->deleteCopiedFiles($paths);

            throw $this->refusal('This draft holds an attachment that is not ready to send. The user can send it from Drafts in Relaticle.');
        }

        $original = $this->original($user, $draft);
        $isForward = $draft->creation_source === EmailCreationSource::FORWARD;
        $bodyHtml = $this->renderer->renderForSending((string) $draft->body?->body_html);

        return [
            'connected_account_id' => $draft->connected_account_id,
            'subject' => $this->renderer->renderPlainText((string) $draft->subject),
            'body_html' => $original instanceof Email && $user->can('viewBody', $original) ? $original->quotedBelow($bodyHtml, $isForward) : $bodyHtml,
            ...$recipients,
            'in_reply_to_email_id' => $original instanceof Email && ! $isForward ? $original->getKey() : null,
            'creation_source' => $source,
            'batch_id' => null,
            'priority' => EmailPriority::PRIORITY,
            'scheduled_for' => $scheduledFor === null ? null : Date::parse($scheduledFor)->utc(),
            'attachments' => $paths,
            'attachment_file_names' => $names,
            'attachment_attributes' => $attributes,
        ];
    }

    private function lockedDraft(User $user, string $draftId): Email
    {
        $draft = Email::query()
            ->composedBy($user)
            ->where('status', EmailStatus::DRAFT)
            ->whereKey($draftId)
            ->lockForUpdate()
            ->first();

        if (! $draft instanceof Email) {
            throw ValidationException::withMessages(['draft_id' => "Draft with ID [{$draftId}] not found."]);
        }

        if ($draft->creation_source === EmailCreationSource::MASS_SEND) {
            throw $this->refusal('This draft is a mass send, which goes to each person separately. The user can send it from Drafts in Relaticle.');
        }

        return $draft->load(['body', 'participants', 'attachments.email.connectedAccount']);
    }

    /** @return array{to: list<array{email: string, name: null}>, cc: list<array{email: string, name: null}>, bcc: list<array{email: string, name: null}>} */
    private function recipients(Email $draft): array
    {
        $of = fn (EmailParticipantRole $role): array => array_values($draft->participants
            ->where('role', $role)
            ->map(fn (EmailParticipant $participant): array => ['email' => $participant->email_address, 'name' => null])
            ->all());

        return ['to' => $of(EmailParticipantRole::TO), 'cc' => $of(EmailParticipantRole::CC), 'bcc' => $of(EmailParticipantRole::BCC)];
    }

    /** @param  array{to: list<array{email: string, name: null}>, cc: list<array{email: string, name: null}>, bcc: list<array{email: string, name: null}>}  $recipients */
    private function assertSendable(User $user, Email $draft, array $recipients): void
    {
        $account = ConnectedAccount::query()
            ->ownedBy($user, $user->currentWorkspace)
            ->whereKey($draft->connected_account_id)
            ->first();

        $error = match (true) {
            ! $account instanceof ConnectedAccount || ! $account->isSendable() => 'The mailbox of this draft cannot send right now. List your mailboxes to find one that can, and save the draft there.',
            $recipients['to'] === [] => 'This draft has no recipient. Update it with at least one address in to.',
            blank($draft->subject) => 'This draft has no subject. Update it with one.',
            count($recipients['to']) + count($recipients['cc']) + count($recipients['bcc']) > PrepareAgentEmail::MAX_RECIPIENTS => PrepareAgentEmail::RECIPIENT_LIMIT_MESSAGE,
            default => null,
        };

        if ($error !== null) {
            throw $this->refusal($error);
        }
    }

    private function original(User $user, Email $draft): ?Email
    {
        if (blank($draft->in_reply_to)) {
            return null;
        }

        $original = Email::query()
            ->with('body')
            ->where('workspace_id', $user->current_workspace_id)
            ->where('rfc_message_id', $draft->in_reply_to)
            ->whereIn('status', [EmailStatus::SYNCED, EmailStatus::SENT])
            ->withGlobalScope('visible', new VisibleEmailScope($user))
            ->first();

        return $original instanceof Email && $user->can('view', $original) ? $original : null;
    }

    private function refusal(string $message): ValidationException
    {
        return ValidationException::withMessages(['draft_id' => $message]);
    }
}
