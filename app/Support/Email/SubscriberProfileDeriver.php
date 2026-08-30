<?php

declare(strict_types=1);

namespace App\Support\Email;

use App\Enums\SubscriberTagEnum;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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

        if ($this->hasChatUsage($user)) {
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

    private function hasCrmData(User $user): bool
    {
        $teamIds = $user->allTeams()->pluck('id');

        return Company::query()->whereIn('team_id', $teamIds)->exists()
            || People::query()->whereIn('team_id', $teamIds)->exists()
            || Opportunity::query()->whereIn('team_id', $teamIds)->exists();
    }

    private function hasChatUsage(User $user): bool
    {
        return DB::table('agent_conversation_messages')
            ->where('participant_type', $user->getMorphClass())
            ->where('participant_id', (string) $user->getKey())
            ->where('role', 'user')
            ->exists();
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
