<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Enums\SocialiteProvider;
use App\Models\User;
use Illuminate\Support\Str;

final readonly class MailboxProviderHint
{
    private const array GOOGLE_DOMAINS = ['gmail.com', 'googlemail.com'];

    private const array MICROSOFT_DOMAINS = ['outlook.com', 'hotmail.com', 'live.com', 'msn.com'];

    public static function for(User $user): ?string
    {
        $social = $user->socialAccounts()
            ->whereIn('provider_name', [SocialiteProvider::GOOGLE->value, SocialiteProvider::MICROSOFT->value])
            ->latest()
            ->value('provider_name');

        if ($social === SocialiteProvider::GOOGLE->value) {
            return 'gmail';
        }

        if ($social === SocialiteProvider::MICROSOFT->value) {
            return 'azure';
        }

        $domain = Str::lower(Str::after($user->email, '@'));

        return match (true) {
            in_array($domain, self::GOOGLE_DOMAINS, true) => 'gmail',
            in_array($domain, self::MICROSOFT_DOMAINS, true) => 'azure',
            default => null,
        };
    }
}
