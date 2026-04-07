<?php

declare(strict_types=1);

namespace Relaticle\OnboardSeed\ModelSeeders;

use App\Enums\CustomFields\TaskField as TaskCustomField;
use App\Models\Task;
use App\Models\Team;
use Exception;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Relaticle\OnboardSeed\Support\BaseModelSeeder;
use Relaticle\OnboardSeed\Support\FixtureRegistry;

final class TaskSeeder extends BaseModelSeeder
{
    protected string $modelClass = Task::class;

    protected string $entityType = 'tasks';

    protected array $fieldCodes = [
        TaskCustomField::DESCRIPTION->value,
        TaskCustomField::DUE_DATE->value,
        TaskCustomField::STATUS->value,
        TaskCustomField::PRIORITY->value,
    ];

    protected function createEntitiesFromFixtures(Team $team, Authenticatable $user): void
    {
        $fixtures = $this->loadEntityFixtures();

        foreach ($fixtures as $key => $data) {
            $task = $this->createTaskFromFixture($team, $user, $key, $data);

            if (isset($data['assigned_people']) && is_array($data['assigned_people'])) {
                $this->assignPeopleToTask($task, $data['assigned_people']);
            }
        }
    }

    /**
     * Assign people to a task based on people keys
     *
     * @param  array<int, string>  $peopleKeys
     */
    private function assignPeopleToTask(Task $task, array $peopleKeys): void
    {
        foreach ($peopleKeys as $personKey) {
            $person = FixtureRegistry::get('people', $personKey);

            if (! $person instanceof Model) {
                Log::warning("Person not found for task assignment: {$personKey}");

                continue;
            }

            try {
                $task->people()->attach($person->getKey());
            } catch (Exception $e) {
                report($e);
            }
        }
    }

    /**
     * Create a task from fixture data
     *
     * @param  array<string, mixed>  $data
     */
    private function createTaskFromFixture(
        Team $team,
        Authenticatable $user,
        string $key,
        array $data
    ): Task {
        $attributes = [
            'title' => $data['title'],
            'team_id' => $team->id,
        ];

        $customFields = $data['custom_fields'] ?? [];

        // Define field mappings for custom processing
        $fieldMappings = [
            TaskCustomField::DUE_DATE->value => fn (mixed $value): mixed => is_string($value)
                ? $this->formatDate($this->evaluateTemplateExpression($value))
                : $value,
            TaskCustomField::STATUS->value => 'option',
            TaskCustomField::PRIORITY->value => 'option',
        ];

        // Process custom fields using utility method
        $processedFields = $this->processCustomFieldValues($customFields, $fieldMappings);

        /** @var Task */
        return $this->registerEntityFromFixture($key, $attributes, $processedFields, $team, $user);
    }

    /**
     * Format a date value for the task due date
     *
     * @param  mixed  $dateValue  The date value returned from template expression
     * @return string The formatted date string
     */
    private function formatDate(mixed $dateValue): string
    {
        if ($dateValue instanceof Carbon) {
            return $dateValue->format('Y-m-d H:i:s');
        }

        return (string) $dateValue;
    }
}
