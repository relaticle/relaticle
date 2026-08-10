<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasLabel;

enum ContactCreationMode: string implements HasLabel
{
    /**
     * Automatically create a Person record for every new email address
     * encountered during sync, regardless of direction.
     */
    case All = 'all';

    /**
     * Only create a Person record when the connected account has exchanged
     * email in both directions with the address (sent AND received).
     */
    case Bidirectional = 'bidirectional';

    /**
     * Never auto-create Person records. Only link emails to existing records.
     */
    case None = 'none';

    public function getLabel(): string
    {
        return match ($this) {
            self::All => 'All contacts',
            self::Bidirectional => 'Bidirectional only',
            self::None => 'Never',
        };
    }
}
