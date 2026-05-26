<?php

declare(strict_types=1);

namespace App\Enums;

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

    // Time-decay recency tags (managed by scheduled command)
    case Active7d = 'active-7d';
    case Active30d = 'active-30d';
    case Dormant = 'dormant';

    /** @return list<self> */
    public static function recencyTags(): array
    {
        return [
            self::Active7d,
            self::Active30d,
            self::Dormant,
        ];
    }
}
