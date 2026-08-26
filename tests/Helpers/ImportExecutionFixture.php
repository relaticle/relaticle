<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Jobs\ExecuteImportJob;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;

final class ImportExecutionFixture
{
    /**
     * @param  list<string>  $headers
     * @param  list<array<string, mixed>>  $rows
     * @param  list<ColumnData>  $mappings
     * @return array{0: Import, 1: ImportStore}
     */
    public static function readyStore(
        object $context,
        array $headers,
        array $rows,
        array $mappings,
        ImportEntityType $entityType = ImportEntityType::People,
    ): array {
        $import = Import::factory()->create([
            'team_id' => (string) $context->team->id,
            'user_id' => (string) $context->user->id,
            'entity_type' => $entityType,
            'file_name' => 'test.csv',
            'status' => ImportStatus::Importing,
            'total_rows' => count($rows),
            'headers' => $headers,
            'column_mappings' => collect($mappings)->map(fn (ColumnData $mapping): array => $mapping->toArray())->all(),
        ]);

        $store = ImportStore::create($import->id);
        $store->query()->insert($rows);

        $context->import = $import;
        $context->store = $store;

        return [$import, $store];
    }

    public static function run(object $context): void
    {
        $job = new ExecuteImportJob(
            importId: $context->import->id,
            teamId: (string) $context->team->id,
        );

        $job->handle();
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array{
     *     corrections?: string|null,
     *     skipped?: string|null,
     *     match_action?: string|null,
     *     matched_id?: string|null,
     *     relationships?: string|null
     * }  $overrides
     * @return array{
     *     row_number: int,
     *     raw_data: string|false,
     *     validation: null,
     *     corrections: string|null,
     *     skipped: string|null,
     *     match_action: string|null,
     *     matched_id: string|null,
     *     relationships: string|null
     * }
     */
    public static function row(int $number, array $raw, array $overrides = []): array
    {
        return array_merge([
            'row_number' => $number,
            'raw_data' => json_encode($raw),
            'validation' => null,
            'corrections' => null,
            'skipped' => null,
            'match_action' => null,
            'matched_id' => null,
            'relationships' => null,
        ], $overrides);
    }

    /**
     * @param  list<string>  $options
     */
    public static function customField(
        object $context,
        string $code,
        string $type,
        string $entityType = 'people',
        array $options = [],
    ): CustomField {
        $customField = CustomField::forceCreate([
            'tenant_id' => $context->team->id,
            'code' => $code,
            'name' => ucfirst(str_replace('_', ' ', $code)),
            'type' => $type,
            'entity_type' => $entityType,
            'sort_order' => 1,
            'active' => true,
            'system_defined' => false,
            'validation_rules' => [],
            'settings' => new CustomFieldSettingsData,
        ]);

        foreach ($options as $index => $optionName) {
            $customField->options()->forceCreate([
                'custom_field_id' => $customField->id,
                'tenant_id' => $context->team->id,
                'name' => $optionName,
                'sort_order' => $index + 1,
            ]);
        }

        return $customField->fresh();
    }

    public static function customFieldValue(
        object $context,
        string $entityId,
        string $customFieldId,
    ): ?CustomFieldValue {
        return CustomFieldValue::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $context->team->id)
            ->where('entity_id', $entityId)
            ->where('custom_field_id', $customFieldId)
            ->first();
    }
}
