<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Workspace;

use App\Actions\Onboarding\RemoveSampleData;
use App\Models\User;
use App\Services\WorkspaceActivationFacts;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Tools\Concerns\LimitsPlanSteps;
use Relaticle\Chat\Tools\Concerns\WithConversationContext;

final class RemoveSampleDataTool implements Tool
{
    use LimitsPlanSteps;
    use WithConversationContext;

    public function description(): string
    {
        return 'Propose removing every sample record seeded when the workspace was created: its sample companies, people, opportunities, tasks and notes, in one approval. '
            .'Use this whenever the user wants all the sample, demo, example or placeholder data gone. To remove only some of it, use the list tool with creation_source "system" and that entity\'s delete tool instead. '
            ."The user's own records are never touched. Only the workspace owner can remove sample data.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();
        $workspace = $user->currentWorkspace;

        $planLimitError = $this->planStepLimitError();

        if ($planLimitError !== null) {
            return $this->error($planLimitError);
        }

        if (! $user->ownsWorkspace($workspace)) {
            return $this->error(__('Only the workspace owner can remove the sample data. Tell the user to ask the owner, and do not link to any page.'));
        }

        $counts = array_filter(resolve(WorkspaceActivationFacts::class)->sampleRecordCounts($workspace));

        if ($counts === []) {
            return $this->error(__('This workspace has no sample records left to remove.'));
        }

        $total = array_sum($counts);

        $pending = resolve(PendingActionService::class)->createProposal(
            user: $user,
            conversationId: $this->resolveConversationId(),
            actionClass: RemoveSampleData::class,
            operation: PendingActionOperation::Delete,
            entityType: 'sample_data',
            actionData: ['name' => __('All sample records')],
            displayData: [
                'title' => __('Remove sample data'),
                'summary' => trans_choice('Delete :count sample record|Delete :count sample records', $total, ['count' => $total]),
                'fields' => array_map(
                    static fn (string $table, int $count): array => ['label' => Str::headline($table), 'value' => (string) $count],
                    array_keys($counts),
                    array_values($counts),
                ),
            ],
            turnId: $this->resolveTurnId(),
        );

        return (string) json_encode([
            'type' => 'pending_action',
            'pending_action_id' => $pending->id,
            'turn_id' => $pending->turn_id,
            'action' => class_basename(RemoveSampleData::class),
            'entity_type' => 'sample_data',
            'operation' => 'delete',
            'data' => ['counts' => $counts],
            'display' => $pending->display_data,
            'meta' => ['agent_should_stop' => true],
        ], JSON_UNESCAPED_SLASHES);
    }

    private function error(string $message): string
    {
        return (string) json_encode(['error' => $message], JSON_UNESCAPED_SLASHES);
    }
}
