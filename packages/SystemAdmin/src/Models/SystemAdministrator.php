<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Models;

use Database\Factories\SystemAdministratorFactory;
use Exception;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property SystemAdministratorRole $role
 * @property Carbon|null $email_verified_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name',
    'email',
    'password',
    'role',
])]
#[Hidden([
    'password',
    'remember_token',
])]
#[Table(name: 'system_administrators')]
final class SystemAdministrator extends Authenticatable implements FilamentUser, HasAvatar, MustVerifyEmail
{
    use HasApiTokens;

    /** @use HasFactory<SystemAdministratorFactory> */
    use HasFactory;

    use HasUlids;
    use Notifiable;

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
