<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Timeline\Sources;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Relaticle\ActivityLog\Contracts\TimelineSource;

abstract class AbstractTimelineSource implements TimelineSource
{
    public function __construct(protected int $priority) {}

    public function priority(): int
    {
        return $this->priority;
    }

    protected function dedupKeyFor(string $class, int|string $id, CarbonImmutable $occurredAt): string
    {
        return sprintf(
            '%s:%s:%s',
            $class,
            $id,
            $occurredAt->utc()->format('Y-m-d\TH:i:s'),
        );
    }

    protected function assertRelation(Model $subject, string $relation): Relation
    {
        if (! method_exists($subject, $relation)) {
            throw new InvalidArgumentException(sprintf(
                '%s has no relation method named "%s".',
                $subject::class,
                $relation,
            ));
        }

        $result = $subject->{$relation}();

        if (! $result instanceof Relation) {
            throw new InvalidArgumentException(sprintf(
                '%s::%s() did not return an Eloquent relation.',
                $subject::class,
                $relation,
            ));
        }

        return $result;
    }
}
