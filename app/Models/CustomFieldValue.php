<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CrmEntity;
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

    /** @var list<string> */
    private const array OPTION_TYPES = [
        'select',
        'multi-select',
        'radio',
        'checkbox-list',
        'tags-input',
    ];

    /** @param array<string, mixed> $options */
    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(function () use ($options): bool {
            $entity = $this->getRelationValue('entity');

            if ($entity instanceof Model) {
                $entity->newQueryWithoutScopes()->whereKey($entity->getKey())->lockForUpdate()->first();
            }

            return parent::save($options);
        });
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function matchingSearch(Builder $query, CrmEntity $entity, string $tenantId, string $escapedPattern): void
    {
        $like = "%{$escapedPattern}%";

        $query->withoutGlobalScope(TenantScope::class)
            ->whereColumn('custom_field_values.entity_id', "{$entity->table()}.id")
            ->where('custom_field_values.entity_type', $entity->value)
            ->where('custom_field_values.tenant_id', $tenantId)
            ->where(fn (Builder $match): Builder => $match
                ->where(fn (Builder $richText): Builder => $richText
                    ->whereIn('custom_field_values.custom_field_id', $this->searchableFieldIds($tenantId)->where('type', 'rich-editor'))
                    ->where('custom_field_values.visible_text', 'ilike', $like))
                ->orWhere(fn (Builder $plain): Builder => $plain
                    ->whereIn('custom_field_values.custom_field_id', $this->searchableFieldIds($tenantId)->where('type', '<>', 'rich-editor'))
                    ->where(fn (Builder $columns): Builder => $columns
                        ->where('custom_field_values.text_value', 'ilike', $like)
                        ->orWhere('custom_field_values.string_value', 'ilike', $like)
                        ->orWhereRaw(
                            "custom_field_values.json_value is not null and json_typeof(custom_field_values.json_value) = 'array' and exists (select 1 from json_array_elements_text(custom_field_values.json_value) as elem(val) where elem.val ilike ?)",
                            [$like],
                        ))));
    }

    /** @return Builder<CustomField> */
    private function searchableFieldIds(string $tenantId): Builder
    {
        return CustomField::query()
            ->withoutGlobalScopes()
            ->select('id')
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->whereNotIn('type', self::OPTION_TYPES);
    }
}
