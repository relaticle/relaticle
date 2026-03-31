<?php

declare(strict_types=1);

namespace Relaticle\OnboardSeed\Support;

use App\Enums\CreationSource;
use App\Models\CustomField;
use App\Models\Team;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Relaticle\Flowforge\Services\DecimalPosition;
use Relaticle\OnboardSeed\Contracts\ModelSeederInterface;
use Spatie\EloquentSortable\SortableTrait;

abstract class BaseModelSeeder implements ModelSeederInterface
{
    /** @var Collection<string, mixed> */
    protected Collection $customFieldDefinitions;

    protected string $modelClass;

    protected string $entityType;

    /** @var array<int, string> */
    protected array $fieldCodes = [];

    protected ?string $teamId = null;

    private ?BulkCustomFieldValueWriter $bulkWriter = null;

    private int $positionCounter = 0;

    public function initialize(): self
    {
        if (! isset($this->entityType) && isset($this->modelClass)) {
            $className = class_basename($this->modelClass);
            $this->entityType = Str::plural(Str::snake($className));
        }

        return $this;
    }

    protected function setTeamId(string $teamId): void
    {
        $this->teamId = $teamId;
    }

    /** @return Collection<string, mixed> */
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

    protected function prepareForSeed(Team $team): void
    {
        $this->setTeamId($team->id);
        $this->customFieldDefinitions = $this->customFields();
        $this->positionCounter = 0;
    }

    public function seed(Team $team, Authenticatable $user): void
    {
        $this->prepareForSeed($team);

        $this->createEntitiesFromFixtures($team, $user);

        $this->flushCustomFieldValues();
    }

    abstract protected function createEntitiesFromFixtures(Team $team, Authenticatable $user): void;

    /** @param  array<string, mixed>  $data */
    protected function applyCustomFields(HasCustomFields&Model $model, array $data): void
    {
        if ($this->teamId === null) {
            return;
        }

        foreach ($data as $code => $value) {
            if (isset($this->customFieldDefinitions[$code])) {
                $this->getBulkWriter()->queue(
                    customField: $this->customFieldDefinitions[$code],
                    value: $value,
                    entityId: $model->getKey(),
                    entityType: $model->getMorphClass(),
                    tenantId: $this->teamId,
                );
            }
        }
    }

    protected function getBulkWriter(): BulkCustomFieldValueWriter
    {
        return $this->bulkWriter ??= new BulkCustomFieldValueWriter;
    }

    protected function flushCustomFieldValues(): int
    {
        return $this->getBulkWriter()->flush();
    }

    protected function getOptionId(string $fieldCode, string $optionLabel): mixed
    {
        $field = $this->customFieldDefinitions[$fieldCode] ?? null;

        if (! $field || ! $field->options || $field->options->isEmpty()) {
            return null;
        }

        $option = $field->options->firstWhere('name', $optionLabel);

        if (! $option) {
            Log::warning("Option '{$optionLabel}' not found for field '{$fieldCode}'");

            return null;
        }

        return $option->id;
    }

    /** @return array<string, mixed> */
    protected function getGlobalAttributes(): array
    {
        return [
            'creation_source' => CreationSource::SYSTEM,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    protected function loadEntityFixtures(): array
    {
        return FixtureLoader::load($this->entityType);
    }

    protected function evaluateTemplateExpression(string $template): mixed
    {
        if (! str_starts_with($template, '{{') || ! str_ends_with($template, '}}')) {
            return $template;
        }

        $expression = trim(substr($template, 2, -2));

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
     * @param  array<string, mixed>  $customFields
     * @param  array<string, callable|string>  $fieldMappings
     * @return array<string, mixed>
     */
    protected function processCustomFieldValues(array $customFields, array $fieldMappings = []): array
    {
        $processed = [];

        foreach ($customFields as $code => $value) {
            if (is_string($value) && str_starts_with($value, '{{') && str_ends_with($value, '}}')) {
                $value = $this->evaluateTemplateExpression($value);
            }

            if (isset($fieldMappings[$code])) {
                $processor = $fieldMappings[$code];

                if ($processor === 'option' && is_string($value)) {
                    $value = $this->getOptionId($code, $value);
                } elseif (is_callable($processor)) {
                    $value = $processor($value);
                }
            }

            $processed[$code] = $value;
        }

        return $processed;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $customFields
     */
    protected function registerEntityFromFixture(string $key, array $attributes, array $customFields, Team $team, Authenticatable $user): Model
    {
        $attributes = array_merge($attributes, [
            'team_id' => $team->id,
            'creator_id' => $user->getAuthIdentifier(),
            ...$this->getGlobalAttributes(),
        ]);

        // SortableTrait's creating event is suppressed by withoutEvents(),
        // so we manually assign sequential order_column values.
        if (in_array(SortableTrait::class, class_uses_recursive($this->modelClass), true)) {
            $this->positionCounter++;
            $attributes['order_column'] = bcmul((string) $this->positionCounter, DecimalPosition::DEFAULT_GAP, DecimalPosition::SCALE);
        }

        $entity = resolve($this->modelClass)->create($attributes);
        $this->applyCustomFields($entity, $customFields);

        FixtureRegistry::register($this->entityType, $key, $entity);

        return $entity;
    }
}
