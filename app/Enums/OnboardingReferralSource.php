<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OnboardingReferralSource: string implements HasLabel
{
    case Google = 'google';
    case GitHub = 'github';
    case LinkedIn = 'linkedin';
    case X = 'x';
    case Reddit = 'reddit';
    case YouTube = 'youtube';
    case FriendsCoworker = 'friends_coworker';
    case Newsletter = 'newsletter';
    case AI = 'ai';
    case Podcast = 'podcast';
    case Other = 'other';

    public function getLabel(?string $locale = null): string
    {
        return match ($this) {
            self::Google => __('enums/onboarding-referral-source.google', [], $locale),
            self::GitHub => __('enums/onboarding-referral-source.github', [], $locale),
            self::LinkedIn => __('enums/onboarding-referral-source.linkedin', [], $locale),
            self::X => __('enums/onboarding-referral-source.x', [], $locale),
            self::Reddit => __('enums/onboarding-referral-source.reddit', [], $locale),
            self::YouTube => __('enums/onboarding-referral-source.youtube', [], $locale),
            self::FriendsCoworker => __('enums/onboarding-referral-source.friends_coworker', [], $locale),
            self::Newsletter => __('enums/onboarding-referral-source.newsletter', [], $locale),
            self::AI => __('enums/onboarding-referral-source.ai', [], $locale),
            self::Podcast => __('enums/onboarding-referral-source.podcast', [], $locale),
            self::Other => __('enums/onboarding-referral-source.other', [], $locale),
        };
    }

    /**
     * @return array<string, string>
     */
    public function getSubOptions(?string $locale = null): array
    {
        return match ($this) {
            self::AI => collect(['chatgpt', 'claude', 'gemini', 'perplexity', 'other'])
                ->mapWithKeys(fn (string $key): array => [$key => __("enums/onboarding-referral-source.sub_options.ai.{$key}", [], $locale)])
                ->all(),
            default => [],
        };
    }

    public function toSubscriberTag(): string
    {
        return "referral:{$this->value}";
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Google => 'ri-google-fill',
            self::GitHub => 'ri-github-fill',
            self::LinkedIn => 'ri-linkedin-fill',
            self::X => 'ri-twitter-x-fill',
            self::Reddit => 'ri-reddit-fill',
            self::YouTube => 'ri-youtube-fill',
            self::FriendsCoworker => 'lucide-users',
            self::Newsletter => 'lucide-mail',
            self::AI => 'lucide-bot',
            self::Podcast => 'lucide-mic',
            self::Other => 'lucide-ellipsis',
        };
    }
}
