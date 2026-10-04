<?php

declare(strict_types=1);

namespace App\Actions\CustomFields;

use App\Enums\CrmEntity;
use App\Enums\CustomFields\OpportunityField as OpportunityCustomField;
use App\Enums\CustomFields\TaskField as TaskCustomField;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Relaticle\CustomFields\Data\CustomFieldOptionSettingsData;
use Relaticle\CustomFields\Enums\OptionCategory;
use Relaticle\CustomFields\FieldTypeSystem\Definitions\StatusFieldType;
use Relaticle\CustomFields\Services\TenantContextService;

/**
 * Move the seeded Task status and Opportunity stage fields onto the status field type
 * and categorise the options they were seeded with, one tenant at a time.
 *
 * Fields are matched on entity alias plus code, never on name: a workspace is free to
 * rename the field and its options, and a renamed option keeps a null category so
 * support can see it in the summary and set one in the field editor.
 */
final readonly class ConvertSeededStatusFields
{
    /** @var list<string> */
    private const array ENTITY_TYPES = [
        CrmEntity::Task->value,
        CrmEntity::Opportunity->value,
    ];

    /** @var list<string> */
    private const array CODES = [
        TaskCustomField::STATUS->value,
        OpportunityCustomField::STAGE->value,
    ];

    /**
     * @param  string|null  $tenantId  One tenant, or null for every tenant holding a seeded field.
     * @return array<string, array{converted: int, categorised: int, skipped: int, unmatched: list<string>}>
     */
    public function execute(?string $tenantId = null, bool $dryRun = false): array
    {
        $summaries = [];
        $previousTenantId = TenantContextService::getCurrentTenantId();

        try {
            foreach ($this->tenantIds($tenantId) as $tenant) {
                TenantContextService::setTenantId($tenant);

                $summaries[$tenant] = DB::transaction(fn (): array => $this->convert($tenant, $dryRun));
            }
        } finally {
            TenantContextService::setTenantId($previousTenantId);
        }

        return $summaries;
    }

    /**
     * @return array{converted: int, categorised: int, skipped: int, unmatched: list<string>}
     */
    private function convert(string $tenantId, bool $dryRun): array
    {
        $converted = 0;
        $categorised = 0;
        $skipped = 0;
        $unmatched = [];

        foreach ($this->seededFields($tenantId) as $field) {
            $categories = $this->categoriesFor($field);

            if ($categories === null) {
                continue;
            }

            if ($field->type !== StatusFieldType::KEY) {
                if (! $dryRun) {
                    $this->convertType($field);
                }

                $converted++;
            }

            foreach ($field->options as $option) {
                if ($option->settings->category instanceof OptionCategory) {
                    $skipped++;

                    continue;
                }

                $category = $categories[(string) $option->name] ?? null;

                if (! $category instanceof OptionCategory) {
                    $unmatched[] = "{$field->code}: {$option->name}";

                    continue;
                }

                if (! $dryRun) {
                    $this->categorise($option, $category);
                }

                $categorised++;
            }
        }

        return [
            'converted' => $converted,
            'categorised' => $categorised,
            'skipped' => $skipped,
            'unmatched' => $unmatched,
        ];
    }

    /**
     * The package refuses a type change on a system-defined field, and both seeded fields
     * are system-defined, so the conversion writes past the observer. Storage is unchanged:
     * status and select are both single-choice, so option ids and values survive.
     */
    private function convertType(CustomField $field): void
    {
        $field->type = StatusFieldType::KEY;
        $field->saveQuietly();
    }

    /**
     * The option keeps whatever color it carries: settings is one JSON object, so
     * writing the category alone would drop the color the editor shows.
     */
    private function categorise(CustomFieldOption $option, OptionCategory $category): void
    {
        $option->update([
            'settings' => new CustomFieldOptionSettingsData(
                color: $option->settings->color,
                category: $category,
            ),
        ]);
    }

    /** @return Collection<int, CustomField> */
    private function seededFields(string $tenantId): Collection
    {
        return (new CustomField)->newQuery()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('entity_type', self::ENTITY_TYPES)
            ->whereIn('code', self::CODES)
            ->with(['options' => fn (Relation $query): Relation => $query->withoutGlobalScopes()])
            ->get();
    }

    /** @return list<string> */
    private function tenantIds(?string $tenantId): array
    {
        if ($tenantId !== null && $tenantId !== '') {
            return [$tenantId];
        }

        $ids = (new CustomField)->newQuery()
            ->withoutGlobalScopes()
            ->whereIn('entity_type', self::ENTITY_TYPES)
            ->whereIn('code', self::CODES)
            ->distinct()
            ->orderBy('tenant_id')
            ->pluck('tenant_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        return array_values($ids);
    }

    /** @return array<string, OptionCategory>|null */
    private function categoriesFor(CustomField $field): ?array
    {
        return match ([$field->entity_type, $field->code]) {
            [CrmEntity::Task->value, TaskCustomField::STATUS->value] => TaskCustomField::STATUS->getOptionCategories(),
            [CrmEntity::Opportunity->value, OpportunityCustomField::STAGE->value] => OpportunityCustomField::STAGE->getOptionCategories(),
            default => null,
        };
    }
}
