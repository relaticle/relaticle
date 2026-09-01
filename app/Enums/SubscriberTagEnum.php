<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

enum SubscriberTagEnum: string
{
    case Verified = 'verified';

    // Event-driven tags (additive, never removed)
    case HasCrmData = 'has-crm-data';
    case HasApiToken = 'has-api-token';
    case HasAiUsage = 'has-ai-usage';
    case HasTeamMembers = 'has-team-members';

    // Signup source tags (set once at registration)
    case SignupSourceOrganic = 'signup-source:organic';
    case SignupSourceSocial = 'signup-source:social';

    // Time-decay recency tags (derived from last_login_at)
    case Active7d = 'active-7d';
    case Active30d = 'active-30d';
    case Dormant = 'dormant';

    /** @var list<string> */
    private const array OWNED_PREFIXES = ['use-case:', 'referral:', 'signup-source:'];

    private const int ACTIVE_7D_DAYS = 7;

    private const int ACTIVE_30D_DAYS = 30;

    private const int DORMANT_DAYS = 60;

    /**
     * Whether the app owns this Mailcoach tag. Owned tags are rewritten
     * wholesale on every profile sync; anything else is hand-applied in the
     * Mailcoach UI and always preserved. A retired enum case must stay
     * recognized here, or syncs preserve its tag as foreign forever.
     */
    public static function isOwned(string $tag): bool
    {
        return Str::startsWith($tag, self::OWNED_PREFIXES) || self::tryFrom($tag) instanceof self;
    }

    /**
     * The bucket boundaries are timestamps rather than whole-day counts so that
     * this and applyRecencyWindow() cannot disagree. Counting truncated days
     * here put a user last seen 7.5 days ago in active-7d while the equivalent
     * query put them in active-30d, a full day-wide cohort per boundary.
     */
    public static function recencyBucketFor(?CarbonInterface $lastLoginAt): ?self
    {
        if (! $lastLoginAt instanceof CarbonInterface) {
            return null;
        }

        return match (true) {
            $lastLoginAt->greaterThanOrEqualTo(now()->subDays(self::ACTIVE_7D_DAYS)) => self::Active7d,
            $lastLoginAt->greaterThanOrEqualTo(now()->subDays(self::ACTIVE_30D_DAYS)) => self::Active30d,
            $lastLoginAt->lessThan(now()->subDays(self::DORMANT_DAYS)) => self::Dormant,
            default => null, // 31-60 days: transition window, no tag
        };
    }

    /**
     * Constrains a query to the rows recencyBucketFor() would put in $bucket.
     * An unknown or absent bucket leaves the query untouched.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function applyRecencyWindow(Builder $query, ?string $bucket): Builder
    {
        return match ($bucket) {
            self::Active7d->value => $query->where('last_login_at', '>=', now()->subDays(self::ACTIVE_7D_DAYS)),
            self::Active30d->value => $query
                ->where('last_login_at', '<', now()->subDays(self::ACTIVE_7D_DAYS))
                ->where('last_login_at', '>=', now()->subDays(self::ACTIVE_30D_DAYS)),
            self::Dormant->value => $query->where('last_login_at', '<', now()->subDays(self::DORMANT_DAYS)),
            default => $query,
        };
    }
}
