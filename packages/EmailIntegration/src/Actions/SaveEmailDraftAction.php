<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use RuntimeException;

final readonly class SaveEmailDraftAction
{
    /**
     * Create or update a DRAFT email row. Drafts are never queued, never
     * team-visible (privacy_tier PRIVATE), and never carry reply threading
     * (spec §4 — a minimized reply saves as a plain draft in v1).
     *
     * @param  array{
     *     connected_account_id: string,
     *     subject: ?string,
     *     body_html: ?string,
     *     to: list<string>,
     *     cc: list<string>,
     *     bcc: list<string>,
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
                'has_attachments' => false,
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
     * @param  array{subject: ?string, body_html: ?string, to: list<string>, cc: list<string>, bcc: list<string>}  $data
     */
    private function isEmpty(array $data): bool
    {
        return blank($data['subject'])
            && trim(strip_tags((string) $data['body_html'])) === ''
            && $data['to'] === []
            && $data['cc'] === []
            && $data['bcc'] === [];
    }
}
