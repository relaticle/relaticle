<?php

declare(strict_types=1);

namespace App\Support\CustomFields;

use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Relaticle\CustomFields\Models\CustomFieldValue;
use Relaticle\CustomFields\Services\TenantContextService;

final readonly class RecordNameResolver
{
    /** @var Collection<string, ?string> */
    private Collection $names;

    public function __construct()
    {
        $this->names = new Collection;
    }

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

                $lookupType = (string) $value->customField->lookup_type;

                foreach ($this->ids($value->getValue()) as $id) {
                    $wanted[$lookupType][] = $id;
                }
            }
        }

        foreach ($wanted as $lookupType => $ids) {
            $this->warm($lookupType, $ids);
        }
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<string, ?string>
     */
    public function names(string $lookupType, array $ids): array
    {
        $this->warm($lookupType, $ids);

        $result = [];

        foreach ($ids as $id) {
            $result[$id] = $this->names->get($this->key($lookupType, $id));
        }

        return $result;
    }

    /** @param  array<int, string>  $ids */
    private function warm(string $lookupType, array $ids): void
    {
        $missing = array_values(array_unique(array_filter(
            $ids,
            fn (string $id): bool => $id !== '' && ! $this->names->has($this->key($lookupType, $id)),
        )));

        if ($missing === []) {
            return;
        }

        $teamId = TenantContextService::getCurrentTenantId();
        $entity = $teamId !== null ? CrmEntity::tryFrom($lookupType) : null;

        if ($entity === null) {
            foreach ($missing as $id) {
                $this->names->put($this->key($lookupType, $id), null);
            }

            return;
        }

        $model = $entity->model();

        $found = $model::query()
            ->where('team_id', $teamId)
            ->whereIn('id', $missing)
            ->pluck($entity->titleColumn(), 'id');

        foreach ($missing as $id) {
            $this->names->put($this->key($lookupType, $id), isset($found[$id]) ? (string) $found[$id] : null);
        }
    }

    private function key(string $lookupType, string $id): string
    {
        return $lookupType.':'.$id;
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
