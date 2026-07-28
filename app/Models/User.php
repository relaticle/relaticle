<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\NotificationPreferences;
use App\Enums\Notifications\NotificationChannel;
use App\Enums\Notifications\NotificationType;
use App\Models\Concerns\HasProfilePhoto;
use Database\Factories\UserFactory;
use Exception;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasTeams;
use Laravel\Jetstream\Jetstream;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string $name
 * @property string $email
 * @property string|null $timezone
 * @property string|null $password
 * @property string|null $profile_photo_path
 * @property-read string $profile_photo_url
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $last_login_at
 * @property string|null $mailcoach_subscriber_uuid
 * @property string|null $subscriber_recency_bucket
 * @property string|null $remember_token
 * @property Carbon|null $scheduled_deletion_at
 * @property string|null $two_factor_recovery_codes
 * @property string|null $two_factor_secret
 * @property array<string, mixed>|null $ai_preferences
 * @property array<string, mixed>|null $notification_preferences
 * @property-read Team|null $currentTeam
 */
#[Appends([
    'profile_photo_url',
])]
#[Fillable([
    'name',
    'email',
    'timezone',
    'password',
    'ai_preferences',
    'notification_preferences',
])]
#[Hidden([
    'password',
    'remember_token',
    'two_factor_recovery_codes',
    'two_factor_secret',
    'mailcoach_subscriber_uuid',
    'subscriber_recency_bucket',
])]
final class User extends Authenticatable implements FilamentUser, HasAvatar, HasDefaultTenant, HasTenants, MustVerifyEmail
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasProfilePhoto;
    use HasTeams;
    use HasUlids;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'ai_preferences' => 'array',
            'notification_preferences' => 'array',
            'scheduled_deletion_at' => 'datetime',
        ];
    }

    public function notificationPreferences(): NotificationPreferences
    {
        return new NotificationPreferences($this->notification_preferences ?? []);
    }

    public function wantsNotification(NotificationType $type, NotificationChannel $channel): bool
    {
        return $this->notificationPreferences()->wants($type, $channel);
    }

    /**
     * @return HasMany<UserSocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(UserSocialAccount::class);
    }

    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    public function isScheduledForDeletion(): bool
    {
        return $this->scheduled_deletion_at !== null;
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    #[Scope]
    protected function scheduledForDeletion(Builder $query): Builder
    {
        return $query->whereNotNull('scheduled_deletion_at');
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    #[Scope]
    protected function expiredDeletion(Builder $query): Builder
    {
        return $query->whereNotNull('scheduled_deletion_at')
            ->where('scheduled_deletion_at', '<=', now());
    }

    /**
     * @return BelongsToMany<Task, $this>
     */
    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class);
    }

    /**
     * @return HasMany<Opportunity, $this>
     */
    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class, 'creator_id');
    }

    public function getDefaultTenant(Panel $panel): ?Model
    {
        return $this->currentTeam;
    }

    /**
     * @throws Exception
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'app';
    }

    /**
     * Self-hosters who set REQUIRE_EMAIL_VERIFICATION=false treat every user as
     * verified — every framework, Filament, and policy check that reads
     * hasVerifiedEmail() honors the flag uniformly through this single override.
     */
    public function hasVerifiedEmail(): bool
    {
        if (! config('app.require_email_verification')) {
            return true;
        }

        return parent::hasVerifiedEmail();
    }

    /**
     * @return Collection<int, Team>
     */
    public function getTenants(Panel $panel): Collection
    {
        return $this->allTeams();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->belongsToTeam($tenant);
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Determine whether the user belongs to the team owning the given foreign key.
     *
     * Policies authorize once per table row, so reading a record's `team`
     * relation there costs a query per row — and throws once a query hydrates
     * more than one row, because that is when Eloquent arms its strict
     * lazy-loading guard. Matching the foreign key against the user's own teams
     * keeps authorization off the record's relations entirely.
     */
    public function belongsToTeamId(?string $teamId): bool
    {
        if ($teamId === null) {
            return false;
        }

        if ($this->ownsTeamId($teamId)) {
            return true;
        }

        return $this->membershipForTeamId($teamId) instanceof Membership;
    }

    /**
     * Determine whether the user holds the given role on the team owning the
     * given foreign key.
     */
    public function hasTeamRoleForTeamId(?string $teamId, string $role): bool
    {
        if ($teamId === null) {
            return false;
        }

        if ($this->ownsTeamId($teamId)) {
            return true;
        }

        $membership = $this->membershipForTeamId($teamId);

        if ($membership?->role === null) {
            return false;
        }

        return Jetstream::findRole($membership->role)?->key === $role;
    }

    private function ownsTeamId(string $teamId): bool
    {
        $this->loadMissing('ownedTeams');

        return $this->ownedTeams->contains(fn (Model $team): bool => $team->getKey() === $teamId);
    }

    private function membershipForTeamId(string $teamId): ?Membership
    {
        $this->loadMissing('memberships');

        return $this->memberships->first(fn (Membership $membership): bool => $membership->team_id === $teamId);
    }
}
