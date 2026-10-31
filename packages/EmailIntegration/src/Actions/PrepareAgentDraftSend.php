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
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;
use Relaticle\EmailIntegration\Queries\PersonByEmailQuery;
use Relaticle\EmailIntegration\Services\EmailTemplateRenderService;
use Relaticle\EmailIntegration\Services\ForwardAttachmentCopyService;

final readonly class PrepareAgentDraftSend
{
    public function __construct(
        private EmailTemplateRenderService $renderer,
        private ForwardAttachmentCopyService $attachments,
        private PersonByEmailQuery $people,
        private CheckAgentDraftSend $checkSend,
    ) {}

    /**
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

        // Callers delete the draft in the same transaction, so a second send of it finds nothing.
        $draft = $this->lockedDraft($user, $draftId);
        $recipients = $this->recipients($draft);

        $this->checkSend->execute($user, $draft);

        [$paths, $names, $attributes, $unavailable] = $this->attachments->copyRecords($user, $draft->attachments);

        if ($unavailable !== []) {
            $this->attachments->deleteCopiedFiles($paths);

            throw ValidationException::withMessages(['draft_id' => 'This draft holds an attachment that is not ready to send. The user can send it from Drafts in Relaticle.']);
        }

        $original = $this->original($user, $draft);
        $isForward = $draft->creation_source === EmailCreationSource::FORWARD;
        $person = $this->people->first($recipients['to'][0]['email'], (string) $user->current_workspace_id);
        $person?->loadMissing('company');
        $bodyHtml = $this->renderer->renderForSending((string) $draft->body?->body_html, $person);

        return [
            'connected_account_id' => $draft->connected_account_id,
            'subject' => $this->renderer->renderPlainText((string) $draft->subject, $person),
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
}
