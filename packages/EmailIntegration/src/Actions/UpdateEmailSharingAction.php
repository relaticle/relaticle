<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\ActivityLog\Activity;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Events\ConversationChanged;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Notifications\EmailSharedNotification;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;
use Relaticle\EmailIntegration\Services\EmailSharingService;

final readonly class UpdateEmailSharingAction
{
    public function __construct(
        private EmailSharingService $sharingService,
        private ConversationsQuery $conversations,
    ) {}

    /**
     * @param  array<int, array{shared_with: string|int|array<int, string|int>, tier: string|EmailPrivacyTier}>  $shares
     */
    public function execute(Email $email, User $sharer, EmailPrivacyTier $tier, array $shares): void
    {
        // Defense in depth: only the email owner may change its sharing, regardless of caller.
        abort_unless($email->user_id === $sharer->getKey(), 403);

        $previousAudience = filled($email->conversation_key) && ConversationChanged::broadcasts()
            ? $this->conversations->audience($email->workspace_id, $email->conversation_key)
            : [];

        $newlyGranted = DB::transaction(function () use ($email, $sharer, $tier, $shares): Collection {
            Email::query()->withoutGlobalScopes()->whereKey($email->getKey())->lockForUpdate()->first(['id']);

            $this->sharingService->setEmailTier($email, $tier);

            $alreadyHadAccess = $email->shares()->pluck('shared_with')->all();

            $email->shares()->where('shared_by', $sharer->getKey())->delete();

            return $this->recreateShares($email, $sharer, $shares)
                ->reject(fn (User $recipient, string $userId): bool => $userId === (string) $sharer->getKey()
                    || in_array($userId, $alreadyHadAccess, true));
        });

        if (filled($email->conversation_key)) {
            event(new ConversationChanged($email->workspace_id, $email->conversation_key, $previousAudience));
        }

        $this->logShares($email, $sharer, $newlyGranted);

        $newlyGranted->each(fn (User $recipient) => $recipient->notify(
            new EmailSharedNotification($email, $sharer, $recipient),
        ));
    }

    /** @param  Collection<string, User>  $recipients */
    private function logShares(Email $email, User $sharer, Collection $recipients): void
    {
        $tiers = $email->shares()->whereIn('shared_with', $recipients->keys()->all())->pluck('tier', 'shared_with');

        foreach ($recipients->keys() as $recipientId) {
            activity(Activity::EMAIL_ACCESS_LOG)
                ->performedOn($email)
                ->causedBy($sharer)
                ->event('shared')
                ->withProperties(['shared_with' => $recipientId, 'tier' => $tiers->get($recipientId)])
                ->log('shared');
        }
    }

    /**
     * @param  array<int, array{shared_with: string|int|array<int, string|int>, tier: string|EmailPrivacyTier}>  $shares
     * @return Collection<string, User>
     */
    private function recreateShares(Email $email, User $sharer, array $shares): Collection
    {
        $granted = collect();

        foreach ($shares as $share) {
            $tierForShare = $share['tier'] instanceof EmailPrivacyTier
                ? $share['tier']
                : EmailPrivacyTier::from($share['tier']);

            foreach (Arr::wrap($share['shared_with']) as $sharedWith) {
                $sharedWithUser = User::query()
                    ->inWorkspace($sharer->current_workspace_id)
                    ->whereKey($sharedWith)
                    ->first();

                abort_if($sharedWithUser === null, 403);

                $this->sharingService->shareEmail($email, $sharer, $sharedWithUser, $tierForShare);

                $granted->put((string) $sharedWithUser->getKey(), $sharedWithUser);
            }
        }

        return $granted;
    }
}
