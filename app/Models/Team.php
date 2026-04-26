<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OnboardingReferralSource;
use App\Enums\OnboardingUseCase;
use App\Services\AvatarService;
use Database\Factories\TeamFactory;
use Filament\Models\Contracts\HasAvatar;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Jetstream\Events\TeamCreated;
use Laravel\Jetstream\Events\TeamDeleted;
use Laravel\Jetstream\Events\TeamUpdated;
use Laravel\Jetstream\Team as JetstreamTeam;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * @property string $name
 * @property string $slug
 * @property ?string $invite_link_token
 * @property ?Carbon $invite_link_token_expires_at
 * @property ?OnboardingUseCase $onboarding_use_case
 * @property ?array<string, string> $onboarding_context
 * @property ?OnboardingReferralSource $onboarding_referral_source
 * @property Carbon|null $scheduled_deletion_at
 */
final class Team extends JetstreamTeam implements HasAvatar
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    use HasSlug;
    use HasUlids;

    public const string SLUG_REGEX = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * Slugs reserved for application routes that must not be used as team slugs.
     *
     * @var list<string>
     */
    public const array RESERVED_SLUGS = [
        // Authentication & authorization
        'login', 'logout', 'register', 'signin', 'signout', 'signup',
        'auth', 'oauth', 'sso', 'callback',
        'forgot-password', 'reset-password', 'password-reset', 'verify-email', 'email-verification',
        'confirm-password', 'two-factor-challenge',

        // Administration
        'admin', 'administrator', 'dashboard', 'console', 'root', 'super', 'sysadmin',

        // Account & billing
        'account', 'billing', 'checkout', 'invoices', 'plan', 'plans',
        'pricing', 'settings', 'subscription', 'subscriptions',

        // Teams & orgs
        'teams', 'team', 'org', 'organization', 'workspace', 'invitations', 'invite',
        'team-invitations', 'join',

        // App routes
        'companies', 'people', 'tasks', 'opportunities', 'notes',
        'api-tokens', 'import-history', 'profile', 'scheduled-deletion',
        'opportunities-board', 'tasks-board',

        // Content & info pages
        'about', 'blog', 'docs', 'documentation', 'faq', 'help', 'support',
        'privacy-policy', 'terms-of-service', 'legal', 'security', 'changelog',
        'discord',

        // API & developer
        'api', 'graphql', 'mcp', 'webhooks', 'developer', 'developers', 'connect', 'user', 'users',

        // Marketing & public
        'home', 'welcome', 'features', 'demo', 'enterprise', 'pro',
        'careers', 'jobs', 'partners', 'affiliate', 'store', 'marketplace',

        // Communication
        'mail', 'email', 'contact', 'feedback', 'abuse', 'report',

        // Infrastructure & framework
        'filament', 'livewire', 'storage', 'imports', 'horizon', 'scalar', 'engagement',
        'system-administrators',
        'up', 'health', 'status', 'metrics',
        'static', 'assets', 'cdn', 'public', 'uploads',
        'www', 'ftp', 'ssh', 'dns', 'ns1', 'ns2',

        // Common actions
        'new', 'create', 'edit', 'delete', 'search', 'explore',

        // Misc
        'null', 'undefined', 'error', 'test', 'staging', 'preview',
    ];

    /**
     * How many days an invite-link token stays valid after creation/rotation.
     */
    public const int INVITE_LINK_TTL_DAYS = 7;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'personal_team',
        'onboarding_use_case',
        'onboarding_context',
        'onboarding_referral_source',
    ];

    /** @var list<string> */
    protected $hidden = [
        'invite_link_token',
    ];

    /**
     * The event map for the model.
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => TeamCreated::class,
        'updated' => TeamUpdated::class,
        'deleted' => TeamDeleted::class,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'personal_team' => 'boolean',
            'onboarding_use_case' => OnboardingUseCase::class,
            'onboarding_context' => 'array',
            'onboarding_referral_source' => OnboardingReferralSource::class,
            'invite_link_token_expires_at' => 'datetime',
            'scheduled_deletion_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (Team $team): void {
            if ($team->invite_link_token === null) {
                $team->invite_link_token = Str::random(40);
                $team->invite_link_token_expires_at = now()->addDays(self::INVITE_LINK_TTL_DAYS);
            }
        });
    }

    public function rotateInviteLink(): void
    {
        $this->forceFill([
            'invite_link_token' => Str::random(40),
            'invite_link_token_expires_at' => now()->addDays(self::INVITE_LINK_TTL_DAYS),
        ])->save();
    }

    public function isInviteLinkTokenExpired(): bool
    {
        if ($this->invite_link_token_expires_at === null) {
            return true;
        }

        return $this->invite_link_token_expires_at->isPast();
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom(function (): string {
                $slug = Str::slug($this->name);

                if ($slug === '') {
                    return Str::lower(Str::random(8));
                }

                return $slug;
            })
            ->saveSlugsTo('slug')
            ->preventOverwrite()
            ->doNotGenerateSlugsOnUpdate();
    }

    protected function otherRecordExistsWithSlug(string $slug): bool
    {
        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            return true;
        }

        $query = self::query()->where($this->slugOptions->slugField, $slug)
            ->withoutGlobalScopes();

        if ($this->exists) {
            $query->where($this->getKeyName(), '!=', $this->getKey());
        }

        return $query->exists();
    }

    public function isPersonalTeam(): bool
    {
        return $this->personal_team;
    }

    public function isScheduledForDeletion(): bool
    {
        return $this->scheduled_deletion_at !== null;
    }

    /**
     * @param  Builder<Team>  $query
     * @return Builder<Team>
     */
    #[Scope]
    protected function scheduledForDeletion(Builder $query): Builder
    {
        return $query->whereNotNull('scheduled_deletion_at');
    }

    /**
     * @param  Builder<Team>  $query
     * @return Builder<Team>
     */
    #[Scope]
    protected function expiredDeletion(Builder $query): Builder
    {
        return $query->whereNotNull('scheduled_deletion_at')
            ->where('scheduled_deletion_at', '<=', now());
    }

    public function getFilamentAvatarUrl(): string
    {
        return resolve(AvatarService::class)->generate(name: $this->name, bgColor: '#000000', textColor: '#ffffff');
    }

    /**
     * @return HasMany<People, $this>
     */
    public function people(): HasMany
    {
        return $this->hasMany(People::class);
    }

    /**
     * @return HasMany<Company, $this>
     */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<Opportunity, $this>
     */
    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    /**
     * @return HasMany<Note, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }
}
