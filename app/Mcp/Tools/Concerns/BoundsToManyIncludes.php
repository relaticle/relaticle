<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

/**
 * The relationships a record can hold many of.
 *
 * List and show tools treat them differently. A list tool refuses them outright,
 * a show tool caps them and reports truncation. Both still have to agree on which
 * relationships they are, or a caller gets an include silently expanded on one tool
 * and rejected on the other.
 */
trait BoundsToManyIncludes
{
    /** @var list<string> */
    private const array TO_MANY_INCLUDES = [
        'assignees',
        'companies',
        'notes',
        'opportunities',
        'people',
        'tasks',
    ];

    /**
     * @param  array<int, mixed>  $includes
     * @return list<string>
     */
    protected function toManyIncludes(array $includes): array
    {
        return array_values(array_intersect($includes, self::TO_MANY_INCLUDES));
    }
}
