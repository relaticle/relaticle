<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\CustomFields\CompanyField as CompanyCustomField;
use App\Enums\CustomFields\NoteField as NoteCustomField;
use App\Enums\CustomFields\OpportunityField as OpportunityCustomField;
use App\Enums\CustomFields\PeopleField as PeopleCustomField;
use App\Enums\CustomFields\TaskField as TaskCustomField;
use App\Enums\OnboardingUseCase;
use App\Features\OnboardSeed;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Events\TeamCreated;
use Laravel\Pennant\Feature;
use Relaticle\CustomFields\Data\CustomFieldData;
use Relaticle\CustomFields\Data\CustomFieldSectionData;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Enums\CustomFieldSectionType;
use Relaticle\CustomFields\Enums\OptionCategory;
use Relaticle\CustomFields\Filament\Integration\Migrations\CustomFieldsMigrator;
use Relaticle\OnboardSeed\OnboardSeeder;

final readonly class CreateTeamCustomFields
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
        private CustomFieldsMigrator $migrator,
        private OnboardSeeder $onboardSeeder,
    ) {}

    public function handle(TeamCreated $event): void
    {
        $team = $event->team;

        $this->migrator->setTenantId($team->id);

        DB::transaction(function (): void {
            foreach (self::MODEL_ENUM_MAP as $modelClass => $enumClass) {
                foreach ($enumClass::cases() as $enum) {
                    $this->createCustomField($modelClass, $enum);
                }
            }
        });

        if ($team->isPersonalTeam() && Feature::active(OnboardSeed::class)) {
            $team->loadMissing('owner');

            /** @var Authenticatable $owner */
            $owner = $team->owner;

            $fixtureSet = $team->onboarding_use_case instanceof OnboardingUseCase
                ? $team->onboarding_use_case->getFixtureSet()
                : 'sales';

            $this->onboardSeeder->run($owner, $team, $fixtureSet);
        }
    }

    /** @param class-string $model */
    private function createCustomField(string $model, CompanyCustomField|OpportunityCustomField|PeopleCustomField|TaskCustomField|NoteCustomField $enum): void
    {
        $fieldData = new CustomFieldData(
            name: $enum->getDisplayName(),
            code: $enum->value,
            type: $enum->getFieldType(),
            section: new CustomFieldSectionData(
                name: 'General',
                code: 'general',
                type: CustomFieldSectionType::HEADLESS
            ),
            systemDefined: $enum->isSystemDefined(),
            width: $enum->getWidth(),
            settings: new CustomFieldSettingsData(
                list_toggleable_hidden: $enum->isListToggleableHidden(),
                enable_option_colors: $enum->hasColorOptions(),
                allow_multiple: $enum->allowsMultipleValues(),
                max_values: $enum->getMaxValues(),
                unique_per_entity_type: $enum->isUniquePerEntityType(),
            )
        );

        $migrator = $this->migrator->new(
            model: $model,
            fieldData: $fieldData
        );

        $options = $enum->getOptions();
        if ($options !== null) {
            $migrator->options($this->optionPayload($enum, $options));
        }

        $migrator->create();
    }

    /**
     * @param  array<int|string, string>  $options
     * @return list<string|array{name: string, color?: string, category?: OptionCategory}>
     */
    private function optionPayload(CompanyCustomField|OpportunityCustomField|PeopleCustomField|TaskCustomField|NoteCustomField $enum, array $options): array
    {
        $colors = $enum->getOptionColors() ?? [];
        $categories = $enum->getOptionCategories() ?? [];

        return array_values(array_map(function (string $name) use ($colors, $categories): string|array {
            $settings = array_filter([
                'color' => $colors[$name] ?? null,
                'category' => $categories[$name] ?? null,
            ], fn (string|OptionCategory|null $setting): bool => $setting !== null);

            return $settings === [] ? $name : ['name' => $name, ...$settings];
        }, $options));
    }
}
