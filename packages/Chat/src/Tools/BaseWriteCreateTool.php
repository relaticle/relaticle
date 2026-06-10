<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Services\Tools\CustomFieldsDisplayFormatter;
use Relaticle\Chat\Services\Tools\CustomFieldsRequestValidator;
use Relaticle\Chat\Services\Tools\CustomFieldsSchemaDescriber;
use Relaticle\Chat\Tools\Concerns\WithConversationContext;

abstract class BaseWriteCreateTool implements Tool
{
    use WithConversationContext;

    /** @return class-string */
    abstract protected function actionClass(): string;

    abstract protected function entityType(): string;

    abstract public function description(): string;

    /** @return array<string, mixed> */
    abstract protected function entitySchema(JsonSchema $schema): array;

    /** @return array<string, mixed> */
    abstract protected function buildDisplayData(Request $request): array;

    /** @return array<string, mixed> */
    abstract protected function extractActionData(Request $request): array;

    public function schema(JsonSchema $schema): array
    {
        $user = auth()->user();

        $customFieldsDescription = $user instanceof User
            ? resolve(CustomFieldsSchemaDescriber::class)->describe($user->currentTeam, $this->entityType())
            : 'Custom field values as key-value pairs.';

        return array_merge(
            $this->entitySchema($schema),
            ['custom_fields' => $schema->object()->description($customFieldsDescription)],
        );
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();

        $validation = resolve(CustomFieldsRequestValidator::class)
            ->validate($user, $this->entityType(), $request['custom_fields'] ?? null);

        if ($validation->error !== null) {
            return (string) json_encode(['error' => $validation->error]);
        }

        $conversationId = $this->resolveConversationId();

        /** @var array<string, mixed> $actionData */
        $actionData = $this->extractActionData($request);
        if ($validation->cleanFields !== []) {
            $actionData['custom_fields'] = $validation->cleanFields;
        }

        $displayData = $this->buildDisplayData($request);
        $customFieldRows = resolve(CustomFieldsDisplayFormatter::class)
            ->format($user, $this->entityType(), $validation->cleanFields, oldModel: null);

        if ($customFieldRows !== []) {
            $existingFields = $displayData['fields'] ?? [];
            $displayData['fields'] = array_merge(is_array($existingFields) ? $existingFields : [], $customFieldRows);
        }

        $service = resolve(PendingActionService::class);
        $pending = $service->createProposal(
            user: $user,
            conversationId: $conversationId,
            actionClass: $this->actionClass(),
            operation: PendingActionOperation::Create,
            entityType: $this->entityType(),
            actionData: $actionData,
            displayData: $displayData,
        );

        return (string) json_encode([
            'type' => 'pending_action',
            'pending_action_id' => $pending->id,
            'action' => class_basename($this->actionClass()),
            'entity_type' => $this->entityType(),
            'operation' => 'create',
            'data' => $pending->action_data,
            'display' => $pending->display_data,
            'meta' => ['agent_should_stop' => true],
        ], JSON_PRETTY_PRINT);
    }
}
