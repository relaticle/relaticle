<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SystemAdministratorFactory;
use Exception;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $timezone
 * @property string $password
 * @property string|null $app_authentication_secret
 * @property array<string>|null $app_authentication_recovery_codes
 * @property SystemAdministratorRole $role
 * @property CarbonImmutable|null $email_verified_at
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'name',
    'email',
    'timezone',
    'password',
    'role',
])]
#[Hidden([
    'password',
    'remember_token',
])]
#[Table(name: 'system_administrators')]
final class SystemAdministrator extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasAvatar, MustVerifyEmail, PasskeyUser
{
    use HasApiTokens;

    /** @use HasFactory<SystemAdministratorFactory> */
    use HasFactory;

    use HasUlids;
    use InteractsWithAppAuthentication;
    use InteractsWithAppAuthenticationRecovery;
    use Notifiable;
    use PasskeyAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => SystemAdministratorRole::class,
        ];
    }

    /**
     * @throws Exception
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'sysadmin' && $this->hasVerifiedEmail();
    }

    /**
     * Mirrors App\Models\User: REQUIRE_EMAIL_VERIFICATION=false short-circuits
     * the gate everywhere hasVerifiedEmail() is consulted (Filament prompt,
     * canAccessPanel, etc).
     */
    public function hasVerifiedEmail(): bool
    {
        if (! config('app.require_email_verification')) {
            return true;
        }

        return parent::hasVerifiedEmail();
    }

    /**
     * @return HasMany<SystemAdministratorPasskey, $this>
     */
    public function passkeys(): HasMany
    {
        return $this->hasMany(SystemAdministratorPasskey::class, 'user_id');
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return null;
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): SystemAdministratorFactory
    {
        return SystemAdministratorFactory::new();
    }
}
