<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use RuntimeException;

final readonly class SaveEmailDraftAction
{
    /**
     * Create or update a DRAFT email row. Drafts are never queued, never
     * team-visible (privacy_tier PRIVATE), and never carry reply threading
     * (spec §4 — a minimized reply saves as a plain draft in v1).
     *
     * `attachments` holds storage paths already written to {@see EmailAttachment::DISK}
     * by the caller; each becomes an EmailAttachment row on the draft. Rows already
     * attached to the draft are left alone, so re-saving never duplicates them.
     *
     * @param  array{
     *     connected_account_id: string,
     *     subject: ?string,
     *     body_html: ?string,
     *     to: list<string>,
     *     cc: list<string>,
     *     bcc: list<string>,
     *     attachments?: list<string>,
     *     attachment_file_names?: array<string, string>,
     * }  $data
     */
    public function execute(User $user, array $data, ?string $draftId = null): Email
    {
        /** @var ConnectedAccount $account */
        $account = ConnectedAccount::query()
            ->ownedBy($user, $user->currentTeam)
            ->whereKey($data['connected_account_id'])
            ->firstOrFail();

        return DB::transaction(function () use ($user, $account, $data, $draftId): Email {
            $existing = $draftId !== null
                ? Email::query()
                    ->where('user_id', $user->getKey())
                    ->where('team_id', $account->team_id)
                    ->where('status', EmailStatus::DRAFT)
                    ->whereKey($draftId)
                    ->first()
                : null;

            abort_if($draftId !== null && $existing === null, 403);

            if ($this->isEmpty($data)) {
                throw_if($existing === null, RuntimeException::class, 'Cannot save an empty draft.');

                return $existing;
            }

            $attributes = [
                'team_id' => $account->team_id,
                'user_id' => $user->getKey(),
                'connected_account_id' => $account->getKey(),
                'subject' => $data['subject'],
                'snippet' => mb_substr(strip_tags((string) $data['body_html']), 0, 255),
                'sent_at' => null,
                'direction' => EmailDirection::OUTBOUND,
                'folder' => EmailFolder::Drafts,
                'status' => EmailStatus::DRAFT,
                'privacy_tier' => EmailPrivacyTier::PRIVATE,
                // Set below, once the new attachment rows exist — an update must not
                // clear the flag for files a previous save already attached.
                'has_attachments' => $existing instanceof Email && $existing->has_attachments,
                'is_internal' => false,
                'creation_source' => EmailCreationSource::COMPOSE,
            ];

            if ($existing !== null) {
                $existing->update($attributes);
                $draft = $existing;
            } else {
                $draft = Email::query()->create($attributes);
            }

            $draft->body()->updateOrCreate([], [
                'body_html' => $data['body_html'],
                'body_text' => strip_tags((string) $data['body_html']),
            ]);

            $this->attachFiles($draft, $data['attachments'] ?? [], $data['attachment_file_names'] ?? []);

            $draft->participants()->delete();

            foreach (['to', 'cc', 'bcc'] as $role) {
                foreach ($data[$role] as $address) {
                    EmailParticipant::query()->create([
                        'email_id' => $draft->getKey(),
                        'email_address' => $address,
                        'name' => null,
                        'role' => $role,
                    ]);
                }
            }

            return $draft;
        });
    }

    /**
     * @param  array{subject: ?string, body_html: ?string, to: list<string>, cc: list<string>, bcc: list<string>, attachments?: list<string>}  $data
     */
    private function isEmpty(array $data): bool
    {
        return blank($data['subject'])
            && trim(strip_tags((string) $data['body_html'])) === ''
            && $data['to'] === []
            && $data['cc'] === []
            && $data['bcc'] === []
            // A message that is nothing but an attached file is still worth keeping.
            && ($data['attachments'] ?? []) === [];
    }

    /**
     * Attach freshly stored files to the draft. Paths the draft already holds are
     * skipped so re-saving (every minimize/close) cannot duplicate rows.
     *
     * @param  list<string>  $paths
     * @param  array<string, string>  $originalNames  storage path => original client filename
     */
    private function attachFiles(Email $draft, array $paths, array $originalNames): void
    {
        $disk = Storage::disk(EmailAttachment::DISK);

        /** @var list<string> $existingPaths */
        $existingPaths = $draft->attachments()->pluck('storage_path')->all();

        foreach ($paths as $path) {
            if (in_array($path, $existingPaths, true)) {
                continue;
            }
            if (! $disk->exists($path)) {
                continue;
            }
            EmailAttachment::query()->create([
                'email_id' => $draft->getKey(),
                'filename' => $originalNames[$path] ?? basename($path),
                'mime_type' => $disk->mimeType($path) ?: 'application/octet-stream',
                'size' => $disk->size($path),
                'storage_path' => $path,
            ]);
        }

        $draft->update(['has_attachments' => $draft->attachments()->exists()]);
    }
}
