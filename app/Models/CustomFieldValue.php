<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\WorkspaceScope;
use App\Observers\CustomFieldValueObserver;
use Database\Factories\CustomFieldValueFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Relaticle\CustomFields\Models\CustomFieldValue as BaseCustomFieldValue;
use Relaticle\CustomFields\Models\Scopes\TenantScope;

#[ObservedBy(CustomFieldValueObserver::class)]
#[ScopedBy([TenantScope::class])]
final class CustomFieldValue extends BaseCustomFieldValue
{
    /** @use HasFactory<CustomFieldValueFactory> */
    use HasFactory;

    use HasUlids;

    public function ownRecord(): ?Model
    {
        // The value names its own record, so the workspace scope must not hide it where none is bound.
        if (! $this->relationLoaded('entity') || ! $this->getRelation('entity') instanceof Model) {
            $this->setRelation('entity', $this->entity()->withoutGlobalScope(WorkspaceScope::class)->getResults());
        }

        $record = $this->getRelation('entity');

        return $record instanceof Model ? $record : null;
    }

    /** @param array<string, mixed> $options */
    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(function () use ($options): bool {
            $entity = $this->ownRecord();

            if ($entity instanceof Model) {
                $entity->newQueryWithoutScopes()->whereKey($entity->getKey())->lockForUpdate()->first();
            }

            return parent::save($options);
        });
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function holdingAValue(Builder $query, CustomField $field): void
    {
        $column = $field->getValueColumn();

        $query->where('custom_field_id', $field->getKey())->whereNotNull($column);

        if ($column === 'json_value') {
            $query->whereRaw("json_value::text not in ('[]', 'null')");
        }

        if (in_array($column, ['string_value', 'text_value'], true)) {
            $query->where($column, '!=', '');
        }
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function holdingOption(Builder $query, CustomField $field, string $optionId): void
    {
        $query->where('custom_field_id', $field->getKey());

        $column = $field->getValueColumn();

        if ($column === 'json_value') {
            $query->whereJsonContains($column, $optionId);

            return;
        }

        $query->where($column, $optionId);
    }
}
