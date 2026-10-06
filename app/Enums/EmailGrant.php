<?php

declare(strict_types=1);

namespace App\Enums;

use App\Features\EmailIntegration;
use Laravel\Pennant\Feature;

enum EmailGrant: string
{
    case Read = 'email:read';
    case Draft = 'email:draft';
    case Send = 'email:send';

    /** @return list<self> */
    public static function offered(): array
    {
        return Feature::active(EmailIntegration::class) ? self::cases() : [];
    }

    /**
     * @param  array<int, string>  $values
     * @return list<self>
     */
    public static function fromValues(array $values): array
    {
        return array_values(array_filter(
            self::offered(),
            fn (self $grant): bool => in_array($grant->value, $values, true),
        ));
    }

    /** @return array<string, string> */
    public static function passportScopes(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $grant): array => [$grant->value => $grant->consentTitle()])
            ->all();
    }

    public function label(): string
    {
        return match ($this) {
            self::Read => __('access-tokens.permissions.email_read'),
            self::Draft => __('access-tokens.permissions.email_draft'),
            self::Send => __('access-tokens.permissions.email_send'),
        };
    }

    public function consentTitle(): string
    {
        return match ($this) {
            self::Read => __('mcp.consent.permissions.email_read'),
            self::Draft => __('mcp.consent.permissions.email_draft'),
            self::Send => __('mcp.consent.permissions.email_send'),
        };
    }

    /**
     * @param  array<int, WorkspaceCapability>  $capabilities
     * @return list<self>
     */
    public static function grantableWith(array $capabilities): array
    {
        return array_values(array_filter(
            self::offered(),
            fn (self $grant): bool => $grant !== self::Send
                || in_array(WorkspaceCapability::EmailAgentSend, $capabilities, true),
        ));
    }
}
