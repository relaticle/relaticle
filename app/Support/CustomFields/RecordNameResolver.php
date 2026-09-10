<?php

declare(strict_types=1);

namespace App\Support\CustomFields;

use App\Enums\CustomFieldType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Relaticle\CustomFields\Models\CustomFieldValue;
use Relaticle\CustomFields\Services\ValueResolver\LookupAttributeResolver;
use Relaticle\CustomFields\Services\ValueResolver\LookupCache;

final readonly class RecordNameResolver
{
    public function __construct(
        private LookupCache $cache,
        private LookupAttributeResolver $attributes,
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
    /** @param  list<string>  $ids */
    private function warm(string $lookupType, array $ids): void
    {
        $missing = array_values(array_map(
            static fn (int|string $id): string => (string) $id,
            $this->cache->missing($lookupType, $ids),
        ));

        if ($missing === []) {
            return;
        }

        [$lookupInstance, $titleAttribute] = $this->attributes->resolve($lookupType);

        $titles = $lookupInstance->newQuery()
            ->whereIn('id', $missing)
            ->pluck($titleAttribute, 'id')
            ->map(static fn (mixed $title): string => (string) $title)
            ->all();

        $this->cache->remember($lookupType, $titles + array_fill_keys($missing, null));
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
