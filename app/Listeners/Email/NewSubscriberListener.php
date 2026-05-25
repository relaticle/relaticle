<?php

declare(strict_types=1);

namespace App\Listeners\Email;

use App\Data\SubscriberData;
use App\Enums\SubscriberTagEnum;
use App\Jobs\Email\SyncSubscriberJob;
use App\Models\User;
use Illuminate\Auth\Events\Verified;

final class NewSubscriberListener
{
    public function handle(Verified $event): void
    {
        /** @var User $user */
        $user = $event->user;

        if (! $user->hasVerifiedEmail() || ! config('mailcoach-sdk.enabled_subscribers_sync', false)) {
            return;
        }

        $signupSourceTag = $user->socialAccounts()->exists()
            ? SubscriberTagEnum::SignupSourceSocial->value
            : SubscriberTagEnum::SignupSourceOrganic->value;

        $tags = [SubscriberTagEnum::Verified->value, $signupSourceTag];

        $team = $user->currentTeam;

        if ($team?->onboarding_use_case) {
            $tags[] = $team->onboarding_use_case->toSubscriberTag();
        }

        if ($team?->onboarding_referral_source) {
            $tags[] = $team->onboarding_referral_source->toSubscriberTag();
        }

        [$firstName, $lastName] = $this->splitName($user->name);

        dispatch(new SyncSubscriberJob(SubscriberData::from([
            'email' => $user->email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'tags' => $tags,
            'skip_confirmation' => true,
            'user_id' => (string) $user->id,
        ])))->afterCommit();
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
