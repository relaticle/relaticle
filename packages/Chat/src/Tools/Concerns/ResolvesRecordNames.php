<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Concerns;

use Relaticle\Chat\Support\RecordNameResolver;

/**
 * Gives a write tool the shared id-to-name resolver its proposal card is built
 * from. Every tool used to carry a private copy of this lookup, which is how the
 * plan-reference gap appeared in the first place: a fix had to be made six times
 * to hold.
 */
trait ResolvesRecordNames
{
    protected function recordNames(): RecordNameResolver
    {
        return resolve(RecordNameResolver::class);
    }
}
