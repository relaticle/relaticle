<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CustomFieldType;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldValue;
use App\Models\Opportunity;
use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Relaticle\CustomFields\Contracts\CustomsFieldsMigrators;
use Relaticle\CustomFields\Data\CustomFieldData;
use Relaticle\CustomFields\Data\CustomFieldOptionSettingsData;
use Relaticle\CustomFields\Data\CustomFieldSectionData;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Enums\CustomFieldSectionType;
use Relaticle\CustomFields\Services\Visibility\BackendVisibilityService;

/**
 * Applies the team's sales pipeline (config/crm.php) to a workspace through the
 * custom-fields system, so the same setup can be replayed on any install without
 * migrations. Safe to run repeatedly: it renames and adds, and only deletes stage
 * options that no opportunity uses.
 */
#[Description('Aplica al workspace las etapas del pipeline y los campos de config/crm.php')]
#[Signature('crm:configurar-pipeline {team : ID o slug del workspace}')]
final class ConfigurePipelineCommand extends Command
{
    public function handle(CustomsFieldsMigrators $migrator): int
    {
        $identifier = (string) $this->argument('team');

        $team = Team::query()
            ->whereKey($identifier)
            ->orWhere('slug', $identifier)
            ->first();

        if (! $team instanceof Team) {
            $this->error("No existe ningún workspace con ID o slug «{$identifier}».");

            return self::FAILURE;
        }

        $migrator->setTenantId($team->getKey());

        DB::transaction(function () use ($team, $migrator): void {
            $this->configureOpportunities($team, $migrator);
            $this->configureCompanies($team, $migrator);
        });

        $this->info("Pipeline configurado en «{$team->name}».");

        return self::SUCCESS;
    }

    private function configureOpportunities(Team $team, CustomsFieldsMigrators $migrator): void
    {
        $this->renameFields($team, Opportunity::class, $this->stringMap('crm.opportunity.field_names'));

        $amount = $this->findField($team, Opportunity::class, 'amount');

        if ($amount instanceof CustomField) {
            $this->updateSettings($amount, fn (CustomFieldSettingsData $settings): CustomFieldSettingsData => $this->withCurrency($settings));
        }

        $stage = $this->findField($team, Opportunity::class, 'stage');

        if ($stage instanceof CustomField) {
            $this->updateSettings($stage, function (CustomFieldSettingsData $settings): CustomFieldSettingsData {
                $settings->enable_option_colors = true;

                return $settings;
            });

            $this->syncStages($team, $stage);
        }

        $this->ensureSelect($team, $migrator, Opportunity::class, 'owner', 'Responsable', $this->stringList('crm.opportunity.owners'));
        $this->ensureSelect($team, $migrator, Opportunity::class, 'source', 'Origen', $this->stringList('crm.opportunity.sources'));
        $this->ensureField($team, $migrator, Opportunity::class, 'probability', 'Probabilidad (%)', CustomFieldType::NUMBER);
    }

    private function configureCompanies(Team $team, CustomsFieldsMigrators $migrator): void
    {
        $this->renameFields($team, Company::class, $this->stringMap('crm.company.field_names'));

        $this->ensureField($team, $migrator, Company::class, 'cif', 'CIF', CustomFieldType::TEXT);
        $this->ensureSelect($team, $migrator, Company::class, 'sector', 'Sector', $this->stringList('crm.company.sectors'));
    }

    /**
     * Upstream stages listed in `stage_renames` become ours in place, so any
     * opportunity already sitting in them keeps its stage. Leftovers are deleted
     * unless an opportunity still uses them.
     */
    private function syncStages(Team $team, CustomField $stage): void
    {
        $stages = $this->stringMap('crm.opportunity.stages');
        $renames = $this->stringMap('crm.opportunity.stage_renames');
        $positions = array_flip(array_keys($stages));
        $claimed = [];

        foreach ($this->fieldOptions($stage) as $option) {
            $name = (string) $option->getAttribute('name');
            $target = $renames[$name] ?? (array_key_exists($name, $stages) ? $name : null);

            if ($target !== null && ! isset($claimed[$target])) {
                $option->forceFill([
                    'name' => $target,
                    'sort_order' => $positions[$target],
                    'settings' => new CustomFieldOptionSettingsData(color: $stages[$target]),
                ])->save();

                $claimed[$target] = true;

                continue;
            }

            if ($this->optionInUse($stage, $option)) {
                $option->forceFill(['sort_order' => count($stages)])->save();
                $this->warn("La etapa «{$name}» tiene oportunidades y se mantiene. Muévelas y vuelve a ejecutar el comando para quitarla.");

                continue;
            }

            $option->delete();
        }

        foreach ($stages as $name => $color) {
            if (isset($claimed[$name])) {
                continue;
            }

            $this->createOption($team, $stage, $name, $positions[$name], new CustomFieldOptionSettingsData(color: $color));
        }
    }

    /**
     * The fields Relaticle creates are system-defined, and CustomFieldObserver
     * refuses to rename those through the model. Like upstream's own migration
     * that retyped `amount`, rename them with a query, then clear the cache the
     * observer would have cleared.
     *
     * @param  class-string<Model>  $model
     * @param  array<string, string>  $names
     */
    private function renameFields(Team $team, string $model, array $names): void
    {
        foreach ($names as $code => $name) {
            $field = $this->findField($team, $model, $code);

            if (! $field instanceof CustomField || $field->getAttribute('name') === $name) {
                continue;
            }

            CustomField::query()
                ->withoutGlobalScopes()
                ->whereKey($field->getKey())
                ->update(['name' => $name]);

            BackendVisibilityService::clearCache((string) $field->getAttribute('entity_type'));
        }
    }

    /**
     * Creates the select if missing; otherwise only adds options it lacks, so
     * options added later from the panel are never removed.
     *
     * @param  class-string<Model>  $model
     * @param  list<string>  $options
     */
    private function ensureSelect(Team $team, CustomsFieldsMigrators $migrator, string $model, string $code, string $name, array $options): void
    {
        $field = $this->findField($team, $model, $code);

        if (! $field instanceof CustomField) {
            $this->ensureField($team, $migrator, $model, $code, $name, CustomFieldType::SELECT, $options);

            return;
        }

        $existing = $this->fieldOptions($field);
        $existingNames = $existing->map(fn (CustomFieldOption $option): string => (string) $option->getAttribute('name'))->all();
        $nextPosition = $existing->count();

        foreach ($options as $option) {
            if (in_array($option, $existingNames, true)) {
                continue;
            }

            $this->createOption($team, $field, $option, $nextPosition++);
        }
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $options
     */
    private function ensureField(Team $team, CustomsFieldsMigrators $migrator, string $model, string $code, string $name, CustomFieldType $type, array $options = []): void
    {
        if ($this->findField($team, $model, $code) instanceof CustomField) {
            return;
        }

        $fieldMigrator = $migrator->new(
            model: $model,
            fieldData: new CustomFieldData(
                name: $name,
                code: $code,
                type: $type->value,
                section: new CustomFieldSectionData(
                    name: 'General',
                    code: 'general',
                    type: CustomFieldSectionType::HEADLESS,
                ),
                settings: new CustomFieldSettingsData(list_toggleable_hidden: false),
            ),
        );

        if ($options !== []) {
            $fieldMigrator->options($options);
        }

        $fieldMigrator->create();

        $this->line("Campo creado: {$name}");
    }

    /**
     * @param  callable(CustomFieldSettingsData): CustomFieldSettingsData  $change
     */
    private function updateSettings(CustomField $field, callable $change): void
    {
        /** @var CustomFieldSettingsData $settings */
        $settings = $field->getAttribute('settings');

        $field->forceFill(['settings' => $change($settings)])->save();
    }

    private function withCurrency(CustomFieldSettingsData $settings): CustomFieldSettingsData
    {
        $settings->additional = [...$settings->additional, 'currency_code' => (string) config('crm.currency')];

        return $settings;
    }

    private function createOption(Team $team, CustomField $field, string $name, int $position, ?CustomFieldOptionSettingsData $settings = null): void
    {
        new CustomFieldOption()->forceFill([
            'tenant_id' => $team->getKey(),
            'custom_field_id' => $field->getKey(),
            'name' => $name,
            'sort_order' => $position,
            'settings' => $settings ?? new CustomFieldOptionSettingsData,
        ])->save();
    }

    private function optionInUse(CustomField $field, CustomFieldOption $option): bool
    {
        return CustomFieldValue::query()
            ->withoutGlobalScopes()
            ->where('custom_field_id', $field->getKey())
            ->where($field->getValueColumn(), $option->getKey())
            ->exists();
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function findField(Team $team, string $model, string $code): ?CustomField
    {
        $field = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $team->getKey())
            ->where('entity_type', Relation::getMorphAlias($model))
            ->where('code', $code)
            ->first();

        return $field instanceof CustomField ? $field : null;
    }

    /**
     * @return Collection<int, CustomFieldOption>
     */
    private function fieldOptions(CustomField $field): Collection
    {
        return CustomFieldOption::query()
            ->withoutGlobalScopes()
            ->where('custom_field_id', $field->getKey())
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(string $key): array
    {
        return (array) config($key, []);
    }

    /**
     * @return list<string>
     */
    private function stringList(string $key): array
    {
        /** @var list<string> */
        return array_values((array) config($key, []));
    }
}
