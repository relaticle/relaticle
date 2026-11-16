<?php

declare(strict_types=1);

namespace Tests\Evals\Support;

use App\Enums\CreationSource;
use App\Enums\WorkspaceRole;
use App\Models\Company;
use App\Models\Note;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Services\WorkspaceActivationFacts;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Enums\MessageOrigin;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\AgentConversation;
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

    public static function noRecords(EvalWorkspace $w): void
    {
        foreach (['companies', 'people', 'tasks', 'notes', 'opportunities'] as $table) {
            DB::table($table)->where('workspace_id', $w->workspace->getKey())->delete();
        }

        resolve(WorkspaceActivationFacts::class)->forget($w->workspace);
    }

    public static function greetedInAnEmptyWorkspace(EvalWorkspace $w): string
    {
        self::noRecords($w);

        $conversationId = (string) $w->conversation(AgentConversation::PURPOSE_SETUP)->getKey();

        self::userRow($w, $conversationId, (string) MessageOrigin::Greeting->opener());
        self::assistantRow($w, $conversationId, "Welcome, Olive! Your workspace is ready to track deals from Prospecting through Closed Won.\n\n**How do you want to bring your clients in?** Pick a choice below, or a pasted list works too.", []);

        return $conversationId;
    }

    public static function askedForOneCompany(EvalWorkspace $w): string
    {
        $conversationId = self::greetedInAnEmptyWorkspace($w);

        self::userRow($w, $conversationId, 'hi');
        self::assistantRow($w, $conversationId, 'Hi! What is one company you work with right now? Its name alone is enough to get started.', []);

        return $conversationId;
    }

    public static function setupWithOwnRecords(EvalWorkspace $w): string
    {
        self::noRecords($w);

        resolve(CurrentWorkspace::class)->within($w->workspace, function () use ($w): void {
            Company::factory()->for($w->workspace)->create(['name' => 'Northwind Traders']);
            People::factory()->for($w->workspace)->create(['name' => 'Priya Raman']);
        });

        resolve(WorkspaceActivationFacts::class)->forget($w->workspace);

        return self::setupConversation($w);
    }

    public static function setupConversation(EvalWorkspace $w): string
    {
        return (string) $w->conversation(AgentConversation::PURPOSE_SETUP)->getKey();
    }

    public static function regeneratedNotes(EvalWorkspace $w): string
    {
        $conversationId = (string) $w->conversation()->getKey();
        $turnId = (string) Str::ulid();
        $titles = ['Pricing call recap', 'Security review recap'];
        $ask = 'Save two notes: Pricing call recap and Security review recap';

        $ids = resolve(CurrentWorkspace::class)->within($w->workspace, fn (): array => array_map(
            fn (string $title): string => (string) Note::factory()->for($w->workspace)->create(['title' => $title])->getKey(),
            $titles,
        ));

        $pending = self::proposal($w, $conversationId, $turnId, 'note', $titles);

        self::userRow($w, $conversationId, $ask);
        self::assistantRow($w, $conversationId, 'I drafted both notes for your approval.', [
            ['name' => 'CreateNoteTool', 'arguments' => ['records' => self::records('note', $titles)], 'result' => self::envelope($pending, $titles)],
        ]);

        self::decide($pending, PendingActionStatus::Approved, [
            'items' => array_map(fn (string $id): array => ['status' => 'approved', 'id' => $id], $ids),
            'ids' => $ids,
            'count' => 2,
        ]);

        AgentConversationMessage::query()->where('conversation_id', $conversationId)->update(['superseded_at' => now()]);

        self::userRow($w, $conversationId, $ask);
        self::assistantRow($w, $conversationId, 'Sure, tell me if you want any changes to the wording.', []);

        return $conversationId;
    }

    public static function approvedTask(EvalWorkspace $w): string
    {
        $conversationId = (string) $w->conversation()->getKey();
        $turnId = (string) Str::ulid();

        $task = resolve(CurrentWorkspace::class)->within(
            $w->workspace,
            fn (): Task => Task::factory()->for($w->workspace)->create(['title' => 'Research Company 04']),
        );

        $pending = self::proposal($w, $conversationId, $turnId, 'task', ['Research Company 04']);
        $id = (string) $task->getKey();

        self::userRow($w, $conversationId, 'Add a task to research Company 04');
        self::assistantRow($w, $conversationId, 'I drafted "Research Company 04" for your approval.', [
            ['name' => 'CreateTaskTool', 'arguments' => ['records' => self::records('task', ['Research Company 04'])], 'result' => self::envelope($pending, ['Research Company 04'])],
        ]);
        self::decide($pending, PendingActionStatus::Approved, ['items' => [['status' => 'approved', 'id' => $id]], 'ids' => [$id], 'count' => 1]);

        self::userRow($w, $conversationId, 'Thanks. What else is open on Company 04?');
        self::assistantRow($w, $conversationId, 'Nothing else is open on Company 04 right now.', []);

        return $conversationId;
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

        $pending = self::proposal($w, $conversationId, $turnId, 'company', ['Acme Robotics']);
        $envelope = self::envelope($pending, ['Acme Robotics']);

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

        $pending = self::proposal($w, $conversationId, $turnId, $entity, [$title]);
        $arguments = ['records' => self::records($entity, [$title])];

        self::userRow($w, $conversationId, $ask);
        self::assistantRow($w, $conversationId, "I drafted \"{$title}\" for your approval.", [
            ['name' => $tool, 'arguments' => $arguments, 'result' => self::envelope($pending, [$title])],
        ]);

        self::decide($pending, $status, $status === PendingActionStatus::Rejected ? ['items' => [['status' => 'rejected']], 'count' => 0] : []);

        return ['conversationId' => $conversationId, 'turnId' => $turnId];
    }

    /**
     * @param  list<string>  $titles
     */
    private static function proposal(EvalWorkspace $w, string $conversationId, string $turnId, string $entity, array $titles): PendingAction
    {
        $pending = ProposalCardFixture::proposal(
            $w->owner,
            ['_batch' => true, 'records' => self::records($entity, $titles)],
            [
                'title' => 'Create '.ucfirst($entity),
                'summary' => 'Create '.count($titles)." {$entity}",
                'items' => array_map(fn (string $title): array => ['title' => $title, 'summary' => "Create {$entity} \"{$title}\"", 'fields' => [['label' => 'Name', 'value' => $title]]], $titles),
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

    /**
     * @param  list<string>  $titles
     * @return list<array<string, string>>
     */
    private static function records(string $entity, array $titles): array
    {
        return array_map(fn (string $title): array => [self::titleKey($entity) => $title], $titles);
    }

    /**
     * @param  list<string>  $titles
     */
    private static function envelope(PendingAction $pending, array $titles): string
    {
        return json_encode(
            PendingActionEnvelope::for($pending, 'create', ['_batch' => true, 'records' => self::records($pending->entity_type->value, $titles)]),
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
