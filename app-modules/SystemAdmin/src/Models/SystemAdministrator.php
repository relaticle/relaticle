<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Models;

use Database\Factories\SystemAdministratorFactory;
use Exception;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property SystemAdministratorRole $role
 * @property Carbon|null $email_verified_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SystemAdministrator extends Authenticatable implements FilamentUser, HasAvatar, MustVerifyEmail
{
    use HasUlids;
    use HasApiTokens;

    /** @use HasFactory<SystemAdministratorFactory> */
    use HasFactory;

    use Notifiable;

    protected $table = 'system_administrators';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

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
