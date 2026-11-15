<?php

declare(strict_types=1);

namespace App\Actions\CustomFields;

use App\Data\StageOptionSettings;
use App\Enums\WorkspaceCapability;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldValue;
use App\Models\User;
use App\Support\ActivityLog\CustomFieldChangeLog;
use App\Support\CustomFieldOptionPlan;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Relaticle\CustomFields\Services\TenantContextService;

final readonly class SetCustomFieldOptions
{
    private const int CLEAR_CHUNK = 1000;

    public function __construct(private CustomFieldChangeLog $changeLog) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, CustomField $field, array $data, bool $capsClears = true, bool $keepsColors = false): CustomField
    {
        abort_unless(
            $user->hasWorkspaceCapability($user->currentWorkspace?->getKey(), WorkspaceCapability::FieldsManage),
            403,
            'Only workspace owners and admins can manage custom attribute definitions.',
        );

        $workspaceId = $user->currentWorkspace->getKey();

        abort_unless((string) $field->tenant_id === (string) $workspaceId, 404);

        $previousTenantId = TenantContextService::getCurrentTenantId();
        TenantContextService::setTenantId($workspaceId);

        try {
            DB::transaction(function () use ($field, $data, $capsClears, $keepsColors): void {
                $field = CustomField::query()->withoutGlobalScopes()->whereKey($field->getKey())->lock('for no key update')->sole();
                $plan = CustomFieldOptionPlan::validated($field, $data, $capsClears, $keepsColors);
                $options = $this->writeOptions($field, $plan);
                $this->enableOptionColors($field, $plan);
                $removed = array_column($plan->removals, 'option');

                // The change log labels moved values from this relation; a reload would read the temporary names.
                $field->setRelation('options', new EloquentCollection([...$options, ...$removed]));

                foreach ($plan->removals as $removal) {
                    if ($removal['replacement'] !== null) {
                        $this->moveValues($field, $removal['option'], $options[$removal['replacement']]);
                    }

                    if ($removal['clears']) {
                        $this->clearValues($field, $removal['option']);
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

        foreach ($plan->targets as $index => $target) {
            $sortOrder = $index + 1;
            $option = $target['option'];

            if (! $option instanceof CustomFieldOption) {
                $options[] = CustomFieldOption::query()->create([
                    (string) config('custom-fields.database.column_names.tenant_foreign_key') => $field->tenant_id,
                    'custom_field_id' => $field->getKey(),
                    'name' => $target['name'],
                    'sort_order' => $sortOrder,
                    'settings' => new StageOptionSettings(color: $target['color'], celebrate: $target['celebrate']),
                ]);

                continue;
            }

            $changes = array_filter([
                'name' => $option->name !== $target['name'] ? $target['name'] : null,
                'settings' => $this->mergedSettings($option, $target),
            ]);

            if ($changes !== []) {
                $option->update($changes);
            }

            if ($option->sort_order !== $sortOrder) {
                CustomFieldOption::query()->withoutGlobalScopes()->whereKey($option->getKey())->update(['sort_order' => $sortOrder]);
            }

            $options[] = $option;
        }

        return $options;
    }

    /**
     * @param  array{color: ?string, celebrate: ?bool}  $target
     */
    private function mergedSettings(CustomFieldOption $option, array $target): ?StageOptionSettings
    {
        $changes = array_filter([
            'color' => $target['color'] !== null && $target['color'] !== $option->settings->color ? $target['color'] : null,
            'celebrate' => $target['celebrate'] !== null && $target['celebrate'] !== $option->celebrates() ? $target['celebrate'] : null,
        ], static fn (mixed $value): bool => $value !== null);

        return $changes === [] ? null : StageOptionSettings::from([...$option->settings->toArray(), ...$changes]);
    }

    private function enableOptionColors(CustomField $field, CustomFieldOptionPlan $plan): void
    {
        if (! $plan->enablesColors) {
            return;
        }

        $settings = $field->settings;
        $settings->enable_option_colors = true;
        $field->update(['settings' => $settings]);
    }

    private function freeTargetNames(CustomFieldOptionPlan $plan): void
    {
        $targetNames = array_column($plan->targets, 'name');
        $holders = array_column($plan->removals, 'option');

        foreach ($plan->targets as $target) {
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
            ->with(['entity' => function (MorphTo $entity): void {
                $entity->withTrashed();
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

    private function clearValues(CustomField $field, CustomFieldOption $option): void
    {
        $optionId = (string) $option->getKey();
        $column = $field->getValueColumn();

        CustomFieldValue::query()
            ->holdingOption($field, $optionId)
            ->with(['entity' => function (MorphTo $entity): void {
                $entity->withTrashed();
            }])
            ->chunkById(self::CLEAR_CHUNK, function (EloquentCollection $values) use ($field, $column, $optionId): void {
                $cleared = $this->clearColumn(array_values($values->modelKeys()), $column, $optionId);

                $this->changeLog->recordMany($field, $values
                    ->filter(fn (CustomFieldValue $value): bool => in_array((string) $value->getKey(), $cleared, true))
                    ->map(fn (CustomFieldValue $value): ?array => $this->clearedChange($value, $column, $optionId))
                    ->filter()
                    ->all());
            });
    }

    /**
     * @return ?array{0: Model, 1: mixed, 2: mixed}
     */
    private function clearedChange(CustomFieldValue $value, string $column, string $optionId): ?array
    {
        $entity = $value->ownRecord();

        if (! $entity instanceof Model) {
            return null;
        }

        return $column === 'json_value'
            ? [$entity, $value->json_value, collect($value->json_value)->reject(fn (mixed $id): bool => (string) $id === $optionId)->values()]
            : [$entity, $value->getAttribute($column), null];
    }

    /**
     * @param  list<mixed>  $valueIds
     * @return list<string>
     */
    private function clearColumn(array $valueIds, string $column, string $optionId): array
    {
        $table = (new CustomFieldValue)->getTable();
        $marks = implode(',', array_fill(0, count($valueIds), '?'));

        $rows = $column === 'json_value'
            ? DB::select(
                "update {$table} set json_value = case json_typeof(json_value) when 'array' then coalesce((select json_agg(element) from json_array_elements(json_value) as element where element #>> '{}' <> ?), '[]'::json) else '[]'::json end where id in ({$marks}) and json_value::jsonb @> ?::jsonb returning id",
                [$optionId, ...$valueIds, json_encode($optionId)],
            )
            : DB::select("update {$table} set {$column} = null where id in ({$marks}) and {$column} = ? returning id", [...$valueIds, $optionId]);

        return array_values(array_map(fn (object $row): string => (string) data_get($row, 'id'), $rows));
    }
}
