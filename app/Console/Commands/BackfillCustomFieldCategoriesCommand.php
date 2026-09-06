<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CrmEntity;
use App\Enums\CustomFields\OpportunityField as OpportunityCustomField;
use App\Enums\CustomFields\TaskField as TaskCustomField;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Relaticle\CustomFields\Data\CustomFieldOptionSettingsData;
use Relaticle\CustomFields\Enums\OptionCategory;
use Relaticle\CustomFields\Services\TenantContextService;

#[Description('Backfill option categories on the seeded task status and opportunity stage fields')]
#[Signature('custom-fields:backfill-categories
                            {--team= : Specific team ID to backfill (optional)}
                            {--dry-run : Show what would be updated without making changes}')]
final class BackfillCustomFieldCategoriesCommand extends Command
{
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $team = $this->option('team');

        if ($dryRun) {
            $this->warn('Dry run: no changes will be written.');
        }

        $fields = CustomField::withoutGlobalScopes()
            ->whereIn('entity_type', [CrmEntity::Task->value, CrmEntity::Opportunity->value])
            ->whereIn('code', [TaskCustomField::STATUS->value, OpportunityCustomField::STAGE->value])
            ->when(
                is_string($team) && $team !== '',
                fn (Builder $query): Builder => $query->where('tenant_id', $team),
            )
            ->with(['options' => fn (Relation $query): Relation => $query->withoutGlobalScopes()])
            ->orderBy('tenant_id')
            ->get();

        $this->info("Found {$fields->count()} status and stage fields to process");

        $categorised = 0;
        $skipped = 0;
        $renamed = [];

        foreach ($fields as $field) {
            $mapping = $this->categoriesFor($field);

            if ($mapping === null) {
                continue;
            }

            foreach ($field->options as $option) {
                if ($option->settings->category instanceof OptionCategory) {
                    $skipped++;

                    continue;
                }

                $category = $mapping[(string) $option->name] ?? null;

                if (! $category instanceof OptionCategory) {
                    $renamed[] = "Team {$field->tenant_id}, {$field->name}: '{$option->name}'";

                    continue;
                }

                if (! $dryRun) {
                    $this->categorise($field, $option, $category);
                }

                $this->line("  Team {$field->tenant_id}, {$field->name}: '{$option->name}' -> {$category->value}");
                $categorised++;
            }
        }

        $verb = $dryRun ? 'Would categorise' : 'Categorised';

        $this->info("{$verb} {$categorised} options, left {$skipped} already-categorised options untouched.");

        if ($renamed !== []) {
            $this->warn(count($renamed).' renamed options stay uncategorised and need a category in the field editor:');

            foreach ($renamed as $line) {
                $this->line("  {$line}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * The option keeps whatever color it carries: settings is one JSON object, so
     * writing the category alone would drop the color the editor shows.
     */
    private function categorise(CustomField $field, CustomFieldOption $option, OptionCategory $category): void
    {
        TenantContextService::withTenant($field->tenant_id, fn (): bool => $option->update([
            'settings' => new CustomFieldOptionSettingsData(
                color: $option->settings->color,
                category: $category,
            ),
        ]));
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
