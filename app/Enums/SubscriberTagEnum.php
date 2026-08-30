<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonInterface;

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

    /**
     * Whether the app owns this Mailcoach tag. Owned tags are rewritten
     * wholesale on every profile sync; anything else is hand-applied in the
     * Mailcoach UI and always preserved. A retired enum case must stay
     * recognized here, or syncs preserve its tag as foreign forever.
     */
    public static function isOwned(string $tag): bool
    {
        foreach (self::OWNED_PREFIXES as $prefix) {
            if (str_starts_with($tag, $prefix)) {
                return true;
            }
        }

        return self::tryFrom($tag) instanceof self;
    }

    public static function recencyBucketFor(?CarbonInterface $lastLoginAt): ?self
    {
        if (! $lastLoginAt instanceof CarbonInterface) {
            return null;
        }

        $daysSinceLogin = (int) abs($lastLoginAt->diffInDays(now()));

        return match (true) {
            $daysSinceLogin <= 7 => self::Active7d,
            $daysSinceLogin <= 30 => self::Active30d,
            $daysSinceLogin > 60 => self::Dormant,
            default => null, // 31-60 days: transition window, no tag
        };
    }
}
