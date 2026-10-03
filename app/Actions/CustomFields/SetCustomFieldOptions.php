<?php

declare(strict_types=1);

namespace App\Actions\CustomFields;

use App\Enums\WorkspaceCapability;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldValue;
use App\Models\User;
use App\Support\CustomFieldOptionPlan;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Relaticle\CustomFields\Services\TenantContextService;

final readonly class SetCustomFieldOptions
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, CustomField $field, array $data): CustomField
    {
        abort_unless(
            $user->hasWorkspaceCapability($user->currentWorkspace?->getKey(), WorkspaceCapability::FieldsManage),
            403,
            'Only workspace owners and admins can manage custom field definitions.',
        );

        $workspaceId = $user->currentWorkspace->getKey();

        abort_unless((string) $field->tenant_id === (string) $workspaceId, 404);

        $previousTenantId = TenantContextService::getCurrentTenantId();
        TenantContextService::setTenantId($workspaceId);

        try {
            DB::transaction(function () use ($field, $data): void {
                $field = CustomField::query()->withoutGlobalScopes()->whereKey($field->getKey())->lockForUpdate()->sole();
                $plan = CustomFieldOptionPlan::validated($field, $data);
                $options = $this->writeOptions($field, $plan);
                $removed = array_column($plan->removals(), 'option');

                // The change log labels moved values from this relation; a reload would read the temporary names.
                $field->setRelation('options', new EloquentCollection([...$options, ...$removed]));

                foreach ($plan->removals() as $removal) {
                    if ($removal['replacement'] !== null) {
                        $this->moveValues($field, $removal['option'], $options[$removal['replacement']]);
                    }

                    $this->assertUnused($field, $removal['option']);

                    $removal['option']->delete();
                }

                // Sort orders and temporary names bypass option events, which are what clear the schema caches.
                $field->touch();
            });
        } finally {
            TenantContextService::setTenantId($previousTenantId);
        }

        return $field->refresh()->load('options');
    }

    private function assertUnused(CustomField $field, CustomFieldOption $option): void
    {
        if (! CustomFieldValue::query()->holdingOption($field, (string) $option->getKey())->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'options' => __('Records still use ":name", so it cannot be removed.', ['name' => $option->name]),
        ]);
    }

    /**
     * @return list<CustomFieldOption>
     */
    private function writeOptions(CustomField $field, CustomFieldOptionPlan $plan): array
    {
        $this->freeTargetNames($plan);

        $options = [];

        foreach ($plan->targets() as $index => $target) {
            $sortOrder = $index + 1;
            $option = $target['option'];

            if (! $option instanceof CustomFieldOption) {
                $options[] = CustomFieldOption::query()->create([
                    (string) config('custom-fields.database.column_names.tenant_foreign_key') => $field->tenant_id,
                    'custom_field_id' => $field->getKey(),
                    'name' => $target['name'],
                    'sort_order' => $sortOrder,
                ]);

                continue;
            }

            if ($option->name !== $target['name']) {
                $option->update(['name' => $target['name']]);
            }

            if ($option->sort_order !== $sortOrder) {
                CustomFieldOption::query()->withoutGlobalScopes()->whereKey($option->getKey())->update(['sort_order' => $sortOrder]);
            }

            $options[] = $option;
        }

        return $options;
    }

    private function freeTargetNames(CustomFieldOptionPlan $plan): void
    {
        $targetNames = array_column($plan->targets(), 'name');
        $holders = array_column($plan->removals(), 'option');

        foreach ($plan->targets() as $target) {
            if ($target['option'] instanceof CustomFieldOption && $target['option']->name !== $target['name']) {
                $holders[] = $target['option'];
            }
        }

        foreach ($holders as $option) {
            if (! in_array($option->name, $targetNames, true)) {
                continue;
            }

            // A query update keeps the model's name in memory, so the rename that follows logs the real old name.
            CustomFieldOption::query()->withoutGlobalScopes()->whereKey($option->getKey())->update(['name' => "renaming-{$option->getKey()}"]);
        }
    }

    private function moveValues(CustomField $field, CustomFieldOption $from, CustomFieldOption $to): void
    {
        $fromId = (string) $from->getKey();
        $toId = (string) $to->getKey();
        $column = $field->getValueColumn();

        CustomFieldValue::query()
            ->holdingOption($field, $fromId)
            ->with(['entity' => function (Relation $entity): void {
                if ($entity instanceof MorphTo) {
                    $entity->withTrashed();
                }
            }])
            ->eachById(function (CustomFieldValue $value) use ($field, $column, $fromId, $toId): void {
                $value->setRelation('customField', $field);
                $value->setAttribute($column, $column === 'json_value'
                    ? collect($value->json_value)
                        ->map(fn (mixed $id): string => (string) $id === $fromId ? $toId : (string) $id)
                        ->unique()
                        ->values()
                    : $toId);
                $value->save();
            });
    }
}
