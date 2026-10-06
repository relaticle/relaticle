<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\CustomFields\CompanyField as CompanyCustomField;
use App\Enums\CustomFields\NoteField as NoteCustomField;
use App\Enums\CustomFields\OpportunityField as OpportunityCustomField;
use App\Enums\CustomFields\PeopleField as PeopleCustomField;
use App\Enums\CustomFields\TaskField as TaskCustomField;
use App\Enums\OnboardingUseCase;
use App\Events\WorkspaceCreated;
use App\Features\OnboardSeed;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Workspace;
use App\Onboarding\FieldOptionRows;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Facades\Entities;
use Relaticle\CustomFields\Services\Visibility\BackendVisibilityService;
use Relaticle\OnboardSeed\OnboardSeeder;

final readonly class CreateWorkspaceCustomFields
{
    /** @var array<class-string, class-string> */
    private const array MODEL_ENUM_MAP = [
        Company::class => CompanyCustomField::class,
        Opportunity::class => OpportunityCustomField::class,
        Note::class => NoteCustomField::class,
        People::class => PeopleCustomField::class,
        Task::class => TaskCustomField::class,
    ];

    public function __construct(
        private OnboardSeeder $onboardSeeder,
    ) {}

    public function handle(WorkspaceCreated $event): void
    {
        $workspace = $event->workspace;

        $stagePreset = $workspace->onboarding_use_case instanceof OnboardingUseCase
            ? $workspace->onboarding_use_case->pipelineStages()
            : null;

        $this->seedDefaultFields($workspace, $stagePreset);

        if ($workspace->isPersonalWorkspace() && Feature::active(OnboardSeed::class)) {
            $workspace->loadMissing('owner');

            /** @var Authenticatable $owner */
            $owner = $workspace->owner;

            $fixtureSet = $workspace->onboarding_use_case instanceof OnboardingUseCase
                ? $workspace->onboarding_use_case->getFixtureSet()
                : 'sales';

            $this->onboardSeeder->run($owner, $workspace, $fixtureSet);
        }
    }

    /**
     * @param  array<string, string>|null  $stagePreset
     */
    private function seedDefaultFields(Workspace $workspace, ?array $stagePreset): void
    {
        $now = now();
        $fields = [];
        $options = [];
        $entityTypes = [];

        foreach (self::MODEL_ENUM_MAP as $modelClass => $enumClass) {
            $entityType = Entities::getEntity($modelClass)?->getAlias() ?? $modelClass;
            $entityTypes[] = $entityType;

            foreach ($enumClass::cases() as $enum) {
                $field = $this->fieldRow($workspace, $entityType, $enum, $now);
                $colors = $enum === OpportunityCustomField::STAGE ? $stagePreset : null;
                $names = $colors !== null ? array_keys($colors) : $enum->getOptions();

                $fields[] = $field;

                array_push($options, ...FieldOptionRows::build(
                    (string) $workspace->getKey(),
                    $field['id'],
                    (string) $field['type'],
                    $names ?? [],
                    $colors ?? $enum->getOptionColors() ?? [],
                    $now,
                ));
            }
        }

        // insert() fires no model events: the defaults every workspace starts with are
        // not an edit anyone made, so seeding them writes nothing to the audit log.
        DB::transaction(function () use ($fields, $options): void {
            CustomField::query()->insert($fields);
            CustomFieldOption::query()->insert($options);
        });

        foreach ($entityTypes as $entityType) {
            BackendVisibilityService::clearCache($entityType);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldRow(
        Workspace $workspace,
        string $entityType,
        CompanyCustomField|OpportunityCustomField|PeopleCustomField|TaskCustomField|NoteCustomField $enum,
        CarbonImmutable $now,
    ): array {
        $field = new CustomField;

        return $field->forceFill([
            'id' => $field->newUniqueId(),
            'tenant_id' => $workspace->getKey(),
            'entity_type' => $entityType,
            'code' => $enum->value,
            'name' => $enum->getDisplayName(),
            'type' => $enum->getFieldType(),
            'lookup_type' => null,
            'width' => $enum->getWidth(),
            'active' => true,
            'system_defined' => $enum->isSystemDefined(),
            'settings' => new CustomFieldSettingsData(
                list_toggleable_hidden: $enum->isListToggleableHidden(),
                enable_option_colors: $enum->hasColorOptions(),
                allow_multiple: $enum->allowsMultipleValues(),
                max_values: $enum->getMaxValues(),
                unique_per_entity_type: $enum->isUniquePerEntityType(),
                additional: $enum->additionalSettings(),
            ),
            'created_at' => $now,
            'updated_at' => $now,
        ])->getAttributes();
    }
}
