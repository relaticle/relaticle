<?php

declare(strict_types=1);

namespace Relaticle\OnboardSeed\Support;

use App\Enums\CreationSource;
use App\Models\CustomField;
use App\Models\Team;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Relaticle\OnboardSeed\Contracts\ModelSeederInterface;

abstract class BaseModelSeeder implements ModelSeederInterface
{
    /**
     * The custom fields collection
     *
     * @var Collection<string, mixed>
     */
    protected Collection $customFieldDefinitions;

    /**
     * The model class this seeder handles
     */
    protected string $modelClass;

    /**
     * The entity type for fixtures (defaults to pluralized model class name)
     */
    protected string $entityType;

    /**
     * The field codes to fetch
     *
     * @var array<int, string>
     */
    protected array $fieldCodes = [];

    /**
     * Personal team ID
     */
    protected ?string $teamId = null;

    /**
     * Initialize the seeder and auto-detect entity type if not set
     */
    public function initialize(): self
    {
        if (! isset($this->entityType) && isset($this->modelClass)) {
            // Default entity type based on model class name
            $className = class_basename($this->modelClass);
            $this->entityType = Str::plural(Str::snake($className));
        }

        return $this;
    }

    /**
     * Set team ID for custom fields retrieval
     */
    protected function setTeamId(string $teamId): void
    {
        $this->teamId = $teamId;
    }

    /**
     * Get custom fields for the model
     *
     * @return Collection<string, mixed>
     */
    public function customFields(): Collection
    {
        if ($this->teamId === null) {
            return collect();
        }

        return CustomField::query()
            ->with('options')
            ->whereTenantId($this->teamId)
            ->forEntity($this->modelClass)
            ->whereIn('code', $this->fieldCodes)
            ->get()
            ->keyBy('code');
    }

    /**
     * Prepare for seeding by loading custom field definitions
     */
    protected function prepareForSeed(Team $team): void
    {
        $this->setTeamId($team->id);
        $this->customFieldDefinitions = $this->customFields();
    }

    /**
     * Run the model seed process
     */
    public function seed(Team $team, Authenticatable $user, array $context = []): array
    {
        $this->prepareForSeed($team);

        return $this->createEntitiesFromFixtures($team, $user, $context);
    }

    /**
     * Create entities from fixtures implementation
     *
     * @param  Team  $team  The team to create data for
     * @param  Authenticatable  $user  The user creating the data
     * @param  array<string, mixed>  $context  Context data from previous seeders
     * @return array<string, mixed> Seeded data for use by subsequent seeders
     */
    abstract protected function createEntitiesFromFixtures(Team $team, Authenticatable $user, array $context = []): array;

    /**
     * Apply custom fields to a model
     *
     * @param  array<string, mixed>  $data  The field data
     */
    protected function applyCustomFields(HasCustomFields $model, array $data): void
    {
        foreach ($data as $code => $value) {
            if (isset($this->customFieldDefinitions[$code])) {
                $model->saveCustomFieldValue($this->customFieldDefinitions[$code], $value);
            }
        }
    }

    /**
     * Get option ID from a custom field by label
     *
     * @param  string  $fieldCode  The field code
     * @param  string  $optionLabel  The option label to find
     * @return mixed The option ID or null if not found
     */
    protected function getOptionId(string $fieldCode, string $optionLabel): mixed
    {
        $field = $this->customFieldDefinitions[$fieldCode] ?? null;

        if (! $field || ! $field->options || $field->options->isEmpty()) {
            return null;
        }

        $option = $field->options->firstWhere('label', $optionLabel)
            ?? $field->options->first();

        return $option ? $option->id : null;
    }

    /**
     * Get global attributes for the model
     *
     * @return array<string, mixed>
     */
    protected function getGlobalAttributes(): array
    {
        return [
            'creation_source' => CreationSource::SYSTEM,
        ];
    }

    /**
     * Load fixtures for this entity type
     *
     * @return array<string, array<string, mixed>> The loaded fixtures
     */
    protected function loadEntityFixtures(): array
    {
        return FixtureLoader::load($this->entityType);
    }

    /**
     * Process dynamic template expressions in fixture data
     * Handles expressions like {{ +5d }} for dates (days, weeks, months, years)
     *
     * @param  string  $template  The template string with {{ expression }}
     * @return mixed The evaluated result
     */
    protected function evaluateTemplateExpression(string $template): mixed
    {
        // If not a template expression, return as is
        if (! str_starts_with($template, '{{') || ! str_ends_with($template, '}}')) {
            return $template;
        }

        $expression = trim(substr($template, 2, -2));

        // Handle simple date patterns: +5d, +1w, +3m, +1y, +2b (business days)
        if (preg_match('/^([+])(\d+)([dwmyb])$/', $expression, $matches)) {
            $value = (int) $matches[2];
            $unit = $matches[3];

            return match ($unit) {
                'd' => now()->addDays($value),
                'w' => now()->addWeeks($value),
                'm' => now()->addMonths($value),
                'y' => now()->addYears($value),
                'b' => now()->addWeekdays($value),
            };
        }

        // Handle specific date keywords
        return match ($expression) {
            'now' => now(),
            'today' => today(),
            'tomorrow' => today()->addDay(),
            'yesterday' => today()->subDay(),
            'nextWeek' => today()->addWeek(),
            'lastWeek' => today()->subWeek(),
            'nextMonth' => today()->addMonth(),
            'lastMonth' => today()->subMonth(),
            default => $template
        };
    }

    /**
     * Process custom field values for use with the model
     * Handles expressions, option lookups, and other transformations
     *
     * @param  array<string, mixed>  $customFields  Custom field data from fixture
     * @param  array<string, callable|string>  $fieldMappings  Optional mappings of field codes to processors
     * @return array<string, mixed> Processed custom field data
     */
    protected function processCustomFieldValues(array $customFields, array $fieldMappings = []): array
    {
        $processed = [];

        foreach ($customFields as $code => $value) {
            // Handle dynamic expressions in string values
            if (is_string($value) && str_starts_with($value, '{{') && str_ends_with($value, '}}')) {
                $value = $this->evaluateTemplateExpression($value);
            }

            // Apply field-specific transformations if defined
            if (isset($fieldMappings[$code])) {
                $processor = $fieldMappings[$code];

                if ($processor === 'option' && is_string($value)) {
                    // Handle option lookup by label
                    $value = $this->getOptionId($code, $value);
                } elseif (is_callable($processor)) {
                    // Apply custom processing function
                    $value = $processor($value);
                }
            }

            $processed[$code] = $value;
        }

        return $processed;
    }

    /**
     * Create and register an entity from fixture data
     *
     * @param  string  $key  The entity key
     * @param  array<string, mixed>  $attributes  The entity attributes
     * @param  array<string, mixed>  $customFields  The custom field values
     * @param  Team  $team  The team to create the entity for
     * @param  Authenticatable  $user  The user creating the entity
     * @return Model The created entity
     */
    protected function registerEntityFromFixture(string $key, array $attributes, array $customFields, Team $team, Authenticatable $user): Model
    {
        $attributes = array_merge($attributes, [
            'team_id' => $team->id,
            'creator_id' => $user->getAuthIdentifier(),
            ...$this->getGlobalAttributes(),
        ]);

        $entity = resolve($this->modelClass)->create($attributes);
        $this->applyCustomFields($entity, $customFields);

        // Register the entity in the registry
        FixtureRegistry::register($this->entityType, $key, $entity);

        return $entity;
    }
}
