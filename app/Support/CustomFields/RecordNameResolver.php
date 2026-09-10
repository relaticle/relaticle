<?php

declare(strict_types=1);

namespace App\Support\CustomFields;

use App\Enums\CustomFieldType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Relaticle\CustomFields\Models\CustomFieldValue;
use Relaticle\CustomFields\Services\ValueResolver\LookupAttributeResolver;
use Relaticle\CustomFields\Services\ValueResolver\LookupCache;

final class RecordNameResolver
{
    /** @var array<string, array<string, true>> */
    private array $attempted = [];

    public function __construct(
        private readonly LookupCache $cache,
        private readonly LookupAttributeResolver $attributes,
    ) {}

    /** @param  iterable<Model>  $models */
    public function prime(iterable $models): void
    {
        $wanted = [];

        foreach ($models as $model) {
            if (! $model->relationLoaded('customFieldValues')) {
                continue;
            }

            foreach ($model->getRelation('customFieldValues') as $value) {
                if (! $value instanceof CustomFieldValue || ! isset($value->getRelations()['customField'])) {
                    continue;
                }

                if ($value->customField->type !== CustomFieldType::RECORD->value) {
                    continue;
                }

                foreach ($this->ids($value->getValue()) as $id) {
                    $wanted[(string) $value->customField->lookup_type][] = $id;
                }
            }
        }

        foreach ($wanted as $lookupType => $ids) {
            $this->warm($lookupType, $ids);
        }
    }

    public function name(string $lookupType, string $id): ?string
    {
        $this->warm($lookupType, [$id]);

        return $this->cache->titleFor($lookupType, $id);
    }

    /** @param  list<string>  $ids */
    private function warm(string $lookupType, array $ids): void
    {
        $missing = array_values(array_filter(
            $this->cache->missing($lookupType, $ids),
            fn (int|string $id): bool => ! isset($this->attempted[$lookupType][(string) $id]),
        ));

        if ($missing === []) {
            return;
        }

        foreach ($missing as $id) {
            $this->attempted[$lookupType][(string) $id] = true;
        }

        [$lookupInstance, $titleAttribute] = $this->attributes->resolve($lookupType);

        $titles = $lookupInstance->newQuery()
            ->whereIn('id', $missing)
            ->pluck($titleAttribute, 'id')
            ->map(static fn (mixed $title): string => (string) $title)
            ->all();

        $this->cache->remember($lookupType, $titles);
    }

    /** @return list<string> */
    private function ids(mixed $raw): array
    {
        $values = $raw instanceof Collection ? $raw->all() : (array) ($raw ?? []);

        return array_values(array_map(
            fn (mixed $id): string => (string) $id,
            array_filter($values, fn (mixed $id): bool => is_string($id) || is_int($id)),
        ));
    }
}
