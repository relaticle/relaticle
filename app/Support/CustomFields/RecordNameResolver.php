<?php

declare(strict_types=1);

namespace App\Support\CustomFields;

use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
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

    /** @return list<array{id: string, name: ?string}> */
    public function resolve(string $lookupType, mixed $value): array
    {
        $ids = $this->ids($value);

        $this->warm($lookupType, $ids);

        return array_map(
            fn (string $id): array => ['id' => $id, 'name' => $this->names->get($this->key($lookupType, $id))],
            $ids,
        );
    }

    /** @param  array<int, string>  $ids */
    private function warm(string $lookupType, array $ids): void
    {
        $missing = array_values(array_unique(array_filter(
            $ids,
            fn (string $id): bool => ! $this->names->has($this->key($lookupType, $id)),
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
    private function ids(mixed $value): array
    {
        $ids = [];

        foreach (Arr::wrap($value instanceof Collection ? $value->all() : $value) as $id) {
            if ((is_string($id) || is_int($id)) && (string) $id !== '') {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }
}
