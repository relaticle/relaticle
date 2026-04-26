<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OnboardingReferralSource: string implements HasLabel
{
    case Google = 'google';
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

    public function toSubscriberTag(): string
    {
        return "referral:{$this->value}";
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Google => 'ri-google-fill',
            self::LinkedIn => 'ri-linkedin-fill',
            self::X => 'ri-twitter-x-fill',
            self::Reddit => 'ri-reddit-fill',
            self::YouTube => 'ri-youtube-fill',
            self::FriendsCoworker => 'ri-group-line',
            self::Newsletter => 'ri-mail-line',
            self::AI => 'ri-robot-line',
            self::Podcast => 'ri-mic-line',
            self::Other => 'ri-more-line',
        };
    }
}
