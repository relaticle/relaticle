<?php

declare(strict_types=1);

namespace App\Enums;

use App\Features\SocialAuth;
use Laravel\Pennant\Feature;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;

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
            // Work accounts report a user principal name on a domain their tenant verified. Personal accounts
            // carry no such guarantee, which isAuthoritativeForEmailOf() checks before an account is replaced.
            self::MICROSOFT => true,
        };
    }

    // Each provider's documented rule for owning an address, not just having verified it once: Google
    // for Gmail and Workspace (hd) accounts, Microsoft for work and school tenants.
    public function isAuthoritativeForEmailOf(SocialiteUser $user): bool
    {
        if (! $this->vouchesForEmailOf($user) || ! $user instanceof AbstractUser) {
            return false;
        }

        $raw = $user->getRaw();

        return match ($this) {
            self::GOOGLE => str_ends_with(mb_strtolower((string) $user->getEmail()), '@gmail.com') || filled($raw['hd'] ?? null),
            self::MICROSOFT => filled($raw['tid'] ?? null) && $raw['tid'] !== MicrosoftProvider::MS_ENTRA_CONSUMER_TENANT_ID,
        };
    }
}
