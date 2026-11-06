<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

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

        $previousAudience = filled($email->conversation_key)
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

        $newlyGranted->each(fn (User $recipient) => $recipient->notify(
            new EmailSharedNotification($email, $sharer, $recipient),
        ));
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
