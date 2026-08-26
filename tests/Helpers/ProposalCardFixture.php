<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Models\CustomField;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Support\PlanReference;
use Relaticle\Chat\Tools\Company\CreateCompanyTool;
use Relaticle\Chat\Tools\People\CreatePersonTool;
use Relaticle\Chat\Tools\Task\CreateTaskTool;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Data\VisibilityConditionData;
use Relaticle\CustomFields\Data\VisibilityData;
use Relaticle\CustomFields\Enums\ConditionSource;
use Relaticle\CustomFields\Enums\VisibilityMode;
use Relaticle\CustomFields\Enums\VisibilityOperator;
use Spatie\LaravelData\DataCollection;

final class ProposalCardFixture
{
    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $display
     */
    public static function proposal(User $user, array $action, array $display): PendingAction
    {
        return PendingAction::query()->create([
            'team_id' => $user->currentTeam->getKey(),
            'user_id' => $user->getKey(),
            'conversation_id' => null,
            'action_class' => 'App\\Actions\\Company\\CreateCompany',
            'operation' => PendingActionOperation::Create,
            'entity_type' => 'company',
            'action_data' => $action,
            'display_data' => $display,
            'status' => PendingActionStatus::Pending,
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    /**
     * @param  list<string>  $names
     */
    public static function batchCompany(User $user, array $names): PendingAction
    {
        $records = array_map(static fn (string $name): array => ['name' => $name], $names);
        $items = array_map(static fn (string $name): array => [
            'title' => $name,
            'summary' => "Create company \"{$name}\"",
            'fields' => [['label' => 'Name', 'value' => $name]],
        ], $names);

        return self::proposal(
            $user,
            ['_batch' => true, 'records' => $records],
            ['title' => 'Create Companies', 'summary' => 'Create '.count($names).' companies', 'items' => $items],
        );
    }

    /**
     * @param  array<string, mixed>  $actionData
     */
    public static function task(User $user, array $actionData): PendingAction
    {
        return PendingAction::query()->create([
            'team_id' => $user->currentTeam->getKey(),
            'user_id' => $user->getKey(),
            'conversation_id' => null,
            'action_class' => 'App\\Actions\\Task\\CreateTask',
            'operation' => PendingActionOperation::Create,
            'entity_type' => 'task',
            'action_data' => $actionData,
            'display_data' => ['title' => 'Create Task', 'summary' => 'Create task', 'fields' => []],
            'status' => PendingActionStatus::Pending,
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    public static function batchTask(User $user, array $records): PendingAction
    {
        $items = array_map(static function (array $record): array {
            $title = (string) ($record['title'] ?? '');

            return [
                'title' => 'Create Task',
                'summary' => "Create task \"{$title}\"",
                'fields' => [['label' => 'Title', 'code' => 'title', 'value' => $title]],
            ];
        }, $records);

        return PendingAction::query()->create([
            'team_id' => $user->currentTeam->getKey(),
            'user_id' => $user->getKey(),
            'conversation_id' => null,
            'action_class' => 'App\\Actions\\Task\\CreateTask',
            'operation' => PendingActionOperation::Create,
            'entity_type' => 'task',
            'action_data' => ['_batch' => true, 'records' => $records],
            'display_data' => ['title' => 'Create Tasks', 'summary' => 'Create '.count($records).' tasks', 'items' => $items],
            'status' => PendingActionStatus::Pending,
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    /**
     * @return array{0: CustomField, 1: list<string>}
     */
    public static function seededTaskChoice(Team $team): array
    {
        $status = CustomField::query()
            ->where('tenant_id', $team->getKey())
            ->where('entity_type', 'task')
            ->where('code', 'status')
            ->with('options')
            ->first();

        expect($status)->not->toBeNull('seeded task status field is required for this test');

        $optionIds = $status->options->map(fn (mixed $option): string => (string) $option->id)->values()->all();

        expect($optionIds)->not->toBeEmpty();

        return [$status, $optionIds];
    }

    public static function taskFieldWithVisibilityCondition(Team $team): CustomField
    {
        [$status] = self::seededTaskChoice($team);

        return CustomField::query()->create([
            'tenant_id' => $team->getKey(),
            'entity_type' => 'task',
            'code' => 'completion_note',
            'name' => 'Completion note',
            'type' => 'text',
            'sort_order' => 99,
            'validation_rules' => [],
            'active' => true,
            'system_defined' => false,
            'settings' => new CustomFieldSettingsData(
                visibility: new VisibilityData(
                    mode: VisibilityMode::SHOW_WHEN,
                    conditions: new DataCollection(VisibilityConditionData::class, [
                        new VisibilityConditionData(
                            field_code: $status->code,
                            operator: VisibilityOperator::EQUALS,
                            value: 'Done',
                            source: ConditionSource::CustomField,
                        ),
                    ]),
                ),
            ),
        ]);
    }

    public static function customField(User $user, string $entityType, string $name, string $type): PendingAction
    {
        return PendingAction::query()->create([
            'team_id' => $user->currentTeam->getKey(),
            'user_id' => $user->getKey(),
            'conversation_id' => null,
            'action_class' => 'App\\Actions\\CustomFields\\CreateCustomField',
            'operation' => PendingActionOperation::Create,
            'entity_type' => 'custom_field',
            'action_data' => ['entity_type' => $entityType, 'name' => $name, 'type' => $type],
            'display_data' => [
                'title' => 'Create Custom Field',
                'summary' => "Create \"{$name}\" ({$type}) on {$entityType}",
                'fields' => [
                    ['label' => 'Entity', 'value' => $entityType],
                    ['label' => 'Name', 'value' => $name],
                    ['label' => 'Type', 'value' => $type],
                ],
            ],
            'status' => PendingActionStatus::Pending,
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    /**
     * @return array{0: PendingAction, 1: PendingAction, 2: PendingAction}
     */
    public static function planSteps(User $user): array
    {
        $conversationId = '019dfb00-5555-7000-8000-000000000009';
        $turnId = '01PLANCARDTURNAAAAAAAAAAAA';

        DB::table('agent_conversations')->insert([
            'id' => $conversationId,
            'participant_type' => 'user',
            'participant_id' => (string) $user->getKey(),
            'team_id' => $user->currentTeam->getKey(),
            'title' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $companyTool = self::planTool(CreateCompanyTool::class, $conversationId, $turnId);
        $companyTool->handle(new Request(['records' => [['name' => 'Northwind Traders']]]));
        $company = PendingAction::query()->where('entity_type', 'company')->latest('id')->firstOrFail();

        $personTool = self::planTool(CreatePersonTool::class, $conversationId, $turnId);
        $personTool->handle(new Request([
            'records' => [['name' => 'Priya Raman', 'company_id' => PlanReference::to((string) $company->getKey())]],
        ]));
        $person = PendingAction::query()->where('entity_type', 'people')->latest('id')->firstOrFail();

        $taskTool = self::planTool(CreateTaskTool::class, $conversationId, $turnId);
        $taskTool->handle(new Request([
            'records' => [['title' => 'Call Priya', 'people_ids' => [PlanReference::to((string) $person->getKey())]]],
        ]));
        $task = PendingAction::query()->where('entity_type', 'task')->latest('id')->firstOrFail();

        return [$company, $person, $task];
    }

    /**
     * @param  class-string<CreateCompanyTool|CreatePersonTool|CreateTaskTool>  $class
     */
    private static function planTool(
        string $class,
        string $conversationId,
        string $turnId,
    ): CreateCompanyTool|CreatePersonTool|CreateTaskTool {
        $tool = resolve($class);
        $tool->setConversationId($conversationId);
        $tool->setTurnId($turnId);

        return $tool;
    }
}
