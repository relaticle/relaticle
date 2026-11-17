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

    public function getLabel(): string
    {
        return match ($this) {
            self::Google => __('enums/onboarding-referral-source.google'),
            self::GitHub => __('enums/onboarding-referral-source.github'),
            self::LinkedIn => __('enums/onboarding-referral-source.linkedin'),
            self::X => __('enums/onboarding-referral-source.x'),
            self::Reddit => __('enums/onboarding-referral-source.reddit'),
            self::YouTube => __('enums/onboarding-referral-source.youtube'),
            self::FriendsCoworker => __('enums/onboarding-referral-source.friends_coworker'),
            self::Newsletter => __('enums/onboarding-referral-source.newsletter'),
            self::AI => __('enums/onboarding-referral-source.ai'),
            self::Podcast => __('enums/onboarding-referral-source.podcast'),
            self::Other => __('enums/onboarding-referral-source.other'),
        };
    }

    /**
     * @return array<string, string>
     */
    public function getSubOptions(): array
    {
        return match ($this) {
            self::AI => [
                'chatgpt' => 'ChatGPT',
                'claude' => 'Claude',
                'gemini' => 'Gemini',
                'perplexity' => 'Perplexity',
                'other' => 'Another assistant',
            ],
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
