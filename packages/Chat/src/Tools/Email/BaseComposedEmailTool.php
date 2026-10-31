<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Email;

use App\Enums\WorkspaceCapability;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Tools\BaseWriteCreateTool;
use Relaticle\Chat\Tools\Concerns\ReadsSendTime;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;

abstract class BaseComposedEmailTool extends BaseWriteCreateTool
{
    use ReadsSendTime;

    abstract protected function idKey(): string;

    abstract protected function composedEmail(User $user, string $id): ?Email;

    abstract protected function notFoundError(): string;

    protected function takesSendTime(): bool
    {
        return false;
    }

    /** @param  array<string, mixed>  $record */
    protected function refusal(User $user, Email $email, array $record): ?string
    {
        return null;
    }

    protected function nameAttribute(): string
    {
        return $this->idKey();
    }

    protected function ownedForeignKeys(): array
    {
        return [];
    }

    protected function requiredCapability(): ?WorkspaceCapability
    {
        return WorkspaceCapability::EmailAgentSend;
    }

    public function schema(JsonSchema $schema): array
    {
        $properties = parent::schema($schema);

        $properties['records']->description('The email to act on. Pass exactly one item and make one call per email: each gets its own approval.');

        return $properties;
    }

    public function handle(Request $request): string
    {
        $records = $request['records'] ?? null;

        if (is_array($records) && count($records) > 1) {
            return (string) json_encode(['error' => 'Pass exactly one email per call. Make one call per email: each gets its own approval. Nothing was proposed.'], JSON_UNESCAPED_SLASHES);
        }

        return parent::handle($request);
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        $properties = [
            $this->idKey() => $schema->string()->description('The ID, as listed by the list emails tool or returned when the email was proposed.')->required(),
        ];

        if ($this->takesSendTime()) {
            $properties['scheduled_for'] = $schema->string()->description($this->sendTimeDescription());
        }

        return $properties;
    }

    protected function validateRecord(array $record, User $user): ?string
    {
        if ($this->takesSendTime() && ($error = $this->sendTimeError($record['scheduled_for'] ?? null, $user)) !== null) {
            return $error;
        }

        $email = $this->recordEmail($record);

        return $email instanceof Email ? $this->refusal($user, $email, $record) : $this->notFoundError();
    }

    protected function extractRecordData(array $record): array
    {
        $data = [
            $this->idKey() => (string) $record[$this->idKey()],
            'subject' => $this->subjectOf($this->recordEmail($record)),
        ];

        $sendAt = $this->takesSendTime() ? $this->sendTime($record['scheduled_for'] ?? null, $this->user()) : null;

        if ($sendAt !== null) {
            $data['scheduled_for'] = $sendAt;
        }

        return $data;
    }

    protected function buildRecordDisplay(array $record): array
    {
        $email = $this->recordEmail($record);
        $data = $this->extractRecordData($record);
        $entity = $this->proposalEntity();

        $rows = [['label' => 'Subject', 'value' => $data['subject']]];

        if ($email instanceof Email) {
            $rows[] = ['label' => 'From', 'value' => (string) $email->connectedAccount?->email_address];
            $rows[] = ['label' => 'To', 'value' => $this->recipients($email)];
        }

        if ($email?->status === EmailStatus::QUEUED && $email->scheduled_for !== null) {
            $rows[] = ['label' => 'Scheduled for', 'value' => $this->sendTimeLabel($email->scheduled_for->toIso8601String(), $this->user())];
        }

        if (isset($data['scheduled_for'])) {
            $rows[] = ['label' => 'Send at', 'value' => $this->sendTimeLabel($data['scheduled_for'], $this->user())];
        }

        return [
            'title' => $entity->createTitle(),
            'summary' => $entity->action(PendingActionOperation::Create).": {$data['subject']}",
            'fields' => $rows,
        ];
    }

    /** @param  array<string, mixed>  $record */
    private function recordEmail(array $record): ?Email
    {
        $id = $record[$this->idKey()] ?? null;

        return is_string($id) && $id !== '' ? $this->composedEmail($this->user(), $id) : null;
    }

    private function subjectOf(?Email $email): string
    {
        return filled($email?->subject) ? (string) $email->subject : '(no subject)';
    }

    private function recipients(Email $email): string
    {
        return $email->participants
            ->filter(fn (EmailParticipant $participant): bool => $participant->role === EmailParticipantRole::TO)
            ->pluck('email_address')
            ->implode(', ');
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
