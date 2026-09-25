<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

/**
 * @property string $user_id
 * @property-read SystemAdministrator $user
 */
final class SystemAdministratorPasskey extends Passkey
{
    use HasFactory;

    protected $table = 'system_administrator_passkeys';

    /**
     * The host staff credentials bind to, and the only place it is decided. It
     * follows this panel, not `passkeys.relying_party_id`, which Fortify pins to
     * the customer panel.
     */
    public static function relyingPartyId(): string
    {
        $domain = config('app.sysadmin_domain');

        if (is_string($domain) && $domain !== '') {
            return $domain;
        }

        return (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    /**
     * SYSADMIN_DOMAIN has to do the separating: falling back to the APP_URL host makes
     * the staff relying party a parent of the customer host, which browsers offer there.
     */
    public static function hasDedicatedRelyingParty(): bool
    {
        $domain = config('app.sysadmin_domain');

        if (! is_string($domain) || $domain === '') {
            return false;
        }

        return ! str_ends_with('.'.Passkeys::relyingPartyId(), '.'.$domain);
    }

    /**
     * @return BelongsTo<SystemAdministrator, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(SystemAdministrator::class, 'user_id');
    }
}
