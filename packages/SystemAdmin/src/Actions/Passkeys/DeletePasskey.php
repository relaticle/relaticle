<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Actions\Passkeys;

use Laravel\Passkeys\Events\PasskeyDeleted;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Relaticle\SystemAdmin\Models\SystemAdministratorPasskey;

final readonly class DeletePasskey
{
    public function execute(SystemAdministrator $administrator, SystemAdministratorPasskey $passkey): void
    {
        abort_unless($passkey->user_id === $administrator->getKey(), 403);

        $passkey->delete();

        event(new PasskeyDeleted($administrator, $passkey));
    }
}
