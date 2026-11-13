<?php

declare(strict_types=1);

namespace Tests\Evals\Support;

use App\Enums\CreationSource;
use App\Enums\WorkspaceRole;
use App\Models\Company;
use App\Models\Task;
use App\Models\User;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\AgentConversationMessage;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Support\PendingActionEnvelope;
use Relaticle\Chat\Tools\Task\ListTasksTool;
use Tests\Helpers\ProposalCardFixture;

final readonly class Prior
{
    public static function listedTasksThenQuestioned(EvalWorkspace $w): string
    {
        $arguments = [
            'filter' => ['custom_fields' => ['status' => ['$not_in' => ['Waiting on others', 'Done']]]],
            'per_page' => 25,
        ];

        self::assignTasksToOwner($w);

        Auth::guard('web')->setUser($w->owner);

        $result = resolve(CurrentWorkspace::class)->within(
            $w->workspace,
            fn (): string => resolve(ListTasksTool::class)->handle(new Request($arguments)),
        );

        $conversationId = (string) $w->conversation()->getKey();

        self::userRow($w, $conversationId, 'Show me tasks I can update to “Waiting on others”.');
        self::assistantRow($w, $conversationId, 'These are tasks not already marked “Waiting on others” or “Done,” so they’re eligible to move to “Waiting on others.” Want me to update all of them?', [
            ['name' => 'ListTasksTool', 'arguments' => $arguments, 'result' => $result],
        ]);
        self::userRow($w, $conversationId, 'all of them need to move under that status or not?');
        self::assistantRow($w, $conversationId, 'No, not necessarily. The list included every task not already “Waiting on others” or “Done,” including tasks in “To do,” “Waiting on you,” and “In progress.” Which of those should I move?', []);

        return $conversationId;
    }

    public static function assignTasksToOwner(EvalWorkspace $w): void
    {
        resolve(CurrentWorkspace::class)->within($w->workspace, function () use ($w): void {
            Task::query()->each(fn (Task $task): mixed => $task->assignees()->syncWithoutDetaching([$w->owner->getKey()]));
        });
    }

    public static function sampleOnly(EvalWorkspace $w): void
    {
        foreach (['companies', 'people', 'tasks', 'notes', 'opportunities'] as $table) {
            DB::table($table)
                ->where('workspace_id', $w->workspace->getKey())
                ->update(['creation_source' => CreationSource::SAMPLE->value]);
        }
    }

    public static function viewer(EvalWorkspace $w): EvalWorkspace
    {
        $viewer = User::factory()->create(['name' => 'Vik Viewer']);
        $w->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
        $viewer->switchWorkspace($w->workspace);

        return new EvalWorkspace($viewer, $w->member, $w->workspace);
    }

    /**
     * @return array{conversationId: string, turnId: string}
     */
    public static function approvedCompany(EvalWorkspace $w): array
    {
        $conversationId = (string) $w->conversation()->getKey();
        $turnId = (string) Str::ulid();

        $company = resolve(CurrentWorkspace::class)->within(
            $w->workspace,
            fn (): Company => Company::factory()->for($w->workspace)->create(['name' => 'Acme Robotics']),
        );

        $pending = self::proposal($w, $conversationId, $turnId, 'company', 'Acme Robotics');
        $envelope = self::envelope($pending, 'Acme Robotics');

        self::userRow($w, $conversationId, 'Create a company called Acme Robotics');
        self::assistantRow($w, $conversationId, 'I drafted Acme Robotics for your approval.', [
            ['name' => 'CreateCompanyTool', 'arguments' => ['records' => [['name' => 'Acme Robotics']]], 'result' => $envelope],
        ]);

        $id = (string) $company->getKey();

        self::decide($pending, PendingActionStatus::Approved, [
            'items' => [['status' => 'approved', 'id' => $id]],
            'ids' => [$id],
            'count' => 1,
        ]);

        return ['conversationId' => $conversationId, 'turnId' => $turnId];
    }

    /**
     * @return array{conversationId: string, turnId: string}
     */
    public static function rejectedTask(EvalWorkspace $w): array
    {
        return self::decidedWithoutWrite($w, 'task', 'Call the Acme Robotics buyer', 'CreateTaskTool', 'Create a task to call the Acme Robotics buyer', PendingActionStatus::Rejected);
    }

    /**
     * @return array{conversationId: string, turnId: string}
     */
    public static function supersededNote(EvalWorkspace $w): array
    {
        return self::decidedWithoutWrite($w, 'note', 'Acme Robotics call recap', 'CreateNoteTool', 'Add a note recapping the Acme Robotics call', PendingActionStatus::Superseded);
    }

    /**
     * @return array{conversationId: string, turnId: string}
     */
    private static function decidedWithoutWrite(EvalWorkspace $w, string $entity, string $title, string $tool, string $ask, PendingActionStatus $status): array
    {
        $conversationId = (string) $w->conversation()->getKey();
        $turnId = (string) Str::ulid();

        $pending = self::proposal($w, $conversationId, $turnId, $entity, $title);
        $arguments = ['records' => [[self::titleKey($entity) => $title]]];

        self::userRow($w, $conversationId, $ask);
        self::assistantRow($w, $conversationId, "I drafted \"{$title}\" for your approval.", [
            ['name' => $tool, 'arguments' => $arguments, 'result' => self::envelope($pending, $title)],
        ]);

        self::decide($pending, $status, $status === PendingActionStatus::Rejected ? ['items' => [['status' => 'rejected']], 'count' => 0] : []);

        return ['conversationId' => $conversationId, 'turnId' => $turnId];
    }

    private static function proposal(EvalWorkspace $w, string $conversationId, string $turnId, string $entity, string $title): PendingAction
    {
        $pending = ProposalCardFixture::proposal(
            $w->owner,
            ['_batch' => true, 'records' => [[self::titleKey($entity) => $title]]],
            [
                'title' => 'Create '.ucfirst($entity),
                'summary' => "Create {$entity} \"{$title}\"",
                'items' => [['title' => $title, 'summary' => "Create {$entity} \"{$title}\"", 'fields' => [['label' => 'Name', 'value' => $title]]]],
            ],
        );

        $pending->update([
            'conversation_id' => $conversationId,
            'turn_id' => $turnId,
            'entity_type' => $entity,
            'action_class' => 'App\\Actions\\'.ucfirst($entity).'\\Create'.ucfirst($entity),
        ]);

        return $pending->refresh();
    }

    private static function titleKey(string $entity): string
    {
        return $entity === 'company' ? 'name' : 'title';
    }

    private static function envelope(PendingAction $pending, string $title): string
    {
        return json_encode(
            PendingActionEnvelope::for($pending, 'create', ['_batch' => true, 'records' => [[self::titleKey($pending->entity_type->value) => $title]]]),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private static function decide(PendingAction $pending, PendingActionStatus $status, array $result): void
    {
        $pending->update([
            'status' => $status,
            'resolved_at' => now(),
            'result_data' => $result === [] ? null : $result,
        ]);
    }

    private static function userRow(EvalWorkspace $w, string $conversationId, string $content): void
    {
        self::row($w, $conversationId, 'user', $content, []);
    }

    /**
     * @param  list<array{name: string, arguments: array<string, mixed>, result: string}>  $calls
     */
    private static function assistantRow(EvalWorkspace $w, string $conversationId, string $content, array $calls): void
    {
        self::row($w, $conversationId, 'assistant', $content, $calls);
    }

    /**
     * @param  list<array{name: string, arguments: array<string, mixed>, result: string}>  $calls
     */
    private static function row(EvalWorkspace $w, string $conversationId, string $role, string $content, array $calls): void
    {
        $steps = $calls === [] ? [] : [[
            'content' => $content,
            'reasoning' => '',
            'tool_calls' => array_map(fn (array $call): array => [
                'id' => $callId = 'call_'.Str::ulid(),
                'name' => $call['name'],
                'arguments' => $call['arguments'],
                'result' => $call['result'],
                'result_id' => $callId,
            ], $calls),
            'replay_blocks' => [],
            'provider_tool_calls' => [],
        ]];

        AgentConversationMessage::query()->create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversationId,
            'participant_type' => $w->owner->getMorphClass(),
            'participant_id' => (string) $w->owner->getKey(),
            'agent' => CrmAssistant::class,
            'role' => $role,
            'content' => $content,
            'attachments' => '[]',
            'steps' => json_encode($steps, JSON_THROW_ON_ERROR),
            'usage' => '[]',
            'meta' => '[]',
            'origin' => 'typed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
