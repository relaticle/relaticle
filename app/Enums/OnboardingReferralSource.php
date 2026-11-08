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
            self::Google => 'Google',
            self::GitHub => 'GitHub',
            self::LinkedIn => 'LinkedIn',
            self::X => 'X.com',
            self::Reddit => 'Reddit',
            self::YouTube => 'YouTube',
            self::FriendsCoworker => 'Friends / Coworker',
            self::Newsletter => 'Newsletter',
            self::AI => 'AI',
            self::Podcast => 'Podcast',
            self::Other => 'Other',
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
