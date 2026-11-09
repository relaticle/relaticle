<?php

declare(strict_types=1);

namespace App\Enums;

use App\Features\SocialAuth;
use Laravel\Pennant\Feature;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Contracts\User as SocialiteUser;

enum SocialiteProvider: string
{
    case GOOGLE = 'google';
    case MICROSOFT = 'microsoft';

    public function label(): string
    {
        return match ($this) {
            self::GOOGLE => 'Google',
            self::MICROSOFT => 'Microsoft',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::GOOGLE => 'ri-google-fill',
            self::MICROSOFT => 'ri-microsoft-fill',
        };
    }

    /** @return list<self> */
    public static function available(): array
    {
        return array_values(array_filter(self::cases(), fn (self $provider): bool => $provider->isAvailable()));
    }

    public function isAvailable(): bool
    {
        return Feature::for(null)->active(SocialAuth::class)
            && filled(config("services.{$this->value}.client_id"));
    }

    public function vouchesForEmailOf(SocialiteUser $user): bool
    {
        if (blank($user->getEmail())) {
            return false;
        }

        return match ($this) {
            self::GOOGLE => $user instanceof AbstractUser && filter_var($user->getRaw()['email_verified'] ?? false, FILTER_VALIDATE_BOOL),
            // The Microsoft provider reports the user principal name, which Entra issues only on verified domains.
            self::MICROSOFT => true,
        };
    }
}
