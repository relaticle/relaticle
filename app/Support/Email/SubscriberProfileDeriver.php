<?php

declare(strict_types=1);

namespace App\Support\Email;

use App\Enums\SubscriberTagEnum;
use App\Models\AiSummary;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Relaticle\Chat\Models\AgentConversationMessage;

/**
 * Derives the complete Mailcoach subscriber profile for a user from the
 * database. The single source of truth for every app-owned tag: syncs write
 * this state wholesale instead of shipping per-event deltas.
 */
final readonly class SubscriberProfileDeriver
{
    public function derive(User $user): SubscriberProfile
    {
        [$firstName, $lastName] = $this->splitName($user->name);

        return new SubscriberProfile($user->email, $firstName, $lastName, $this->tags($user));
    }

    /** @return list<string> */
    private function tags(User $user): array
    {
        $tags = [SubscriberTagEnum::Verified->value, $this->signupSourceTag($user)];

        foreach ($user->ownedTeams as $team) {
            /** @var Team $team */
            $tags = [...$tags, ...$team->onboardingSubscriberTags()];
        }

        if ($this->hasCrmData($user)) {
            $tags[] = SubscriberTagEnum::HasCrmData->value;
        }

        if ($user->tokens()->exists()) {
            $tags[] = SubscriberTagEnum::HasApiToken->value;
        }

        if ($user->ownedTeams()->whereHas('users')->exists()) {
            $tags[] = SubscriberTagEnum::HasTeamMembers->value;
        }

        if ($this->hasAiUsage($user)) {
            $tags[] = SubscriberTagEnum::HasAiUsage->value;
        }

        $recencyBucket = SubscriberTagEnum::recencyBucketFor($user->last_login_at);

        if ($recencyBucket instanceof SubscriberTagEnum) {
            $tags[] = $recencyBucket->value;
        }

        $tags = array_values(array_unique($tags));
        sort($tags);

        return $tags;
    }

    /**
     * Signup source is a snapshot of how the account was registered. OAuth
     * logins link a social account to existing organic users at any later
     * point, so only accounts linked at registration time count as social.
     */
    private function signupSourceTag(User $user): string
    {
        $signedUpSocially = $user->socialAccounts()
            ->where('created_at', '<', $user->created_at->addMinutes(5))
            ->exists();

        return $signedUpSocially
            ? SubscriberTagEnum::SignupSourceSocial->value
            : SubscriberTagEnum::SignupSourceOrganic->value;
    }

    /**
     * The single definition of "this account has CRM data". Trigger paths pass
     * the record they just created as $excluding so they can ask whether any
     * OTHER one already existed.
     */
    public function hasCrmData(User $user, ?Model $excluding = null): bool
    {
        $teamIds = $user->allTeams()->pluck('id');

        foreach ([Company::class, People::class, Opportunity::class] as $entity) {
            $query = $entity::query()->whereIn('team_id', $teamIds);

            if ($excluding instanceof $entity) {
                $query->whereKeyNot($excluding->getKey());
            }

            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The single definition of "this account has used AI": an assistant message
     * the user sent, or an AI summary generated anywhere in their workspaces.
     * Trigger paths pass the record they just created as $excluding so they can
     * ask whether any OTHER one already existed.
     */
    public function hasAiUsage(User $user, ?Model $excluding = null): bool
    {
        if (AgentConversationMessage::query()->sentBy($user)->exists()) {
            return true;
        }

        $summaries = AiSummary::query()->whereIn('team_id', $user->allTeams()->pluck('id'));

        if ($excluding instanceof AiSummary) {
            $summaries->whereKeyNot($excluding->getKey());
        }

        return $summaries->exists();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $fullName): array
    {
        $trimmed = trim($fullName);

        if ($trimmed === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $trimmed, 2) ?: [$trimmed];

        return [$parts[0], $parts[1] ?? ''];
    }
}
