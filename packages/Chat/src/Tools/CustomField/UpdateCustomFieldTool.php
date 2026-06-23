<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\CustomField;

use App\Actions\CustomFields\UpdateCustomField;
use App\Models\CustomField;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Tools\Concerns\WithConversationContext;
use Relaticle\Chat\Tools\CustomField\Concerns\ResolvesOwnedCustomField;

final class UpdateCustomFieldTool implements Tool
{
    use ResolvesOwnedCustomField;
    use WithConversationContext;

    public function name(): string
    {
        return 'UpdateCustomFieldTool';
    }

    public function description(): string
    {
        return 'Propose renaming a custom field or toggling its active status. Admin-only. Cannot modify system-defined fields. Returns a proposal for user approval.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'entity_type' => $schema->string()
                ->description('The CRM entity the field belongs to: company, people, opportunity, task, or note.')
                ->required(),
            'code' => $schema->string()
                ->description('The machine code of the custom field to update, as shown in the custom_fields field list for that entity (e.g. "industry").')
                ->required(),
            'name' => $schema->string()
                ->description('The new display name for the field.'),
            'active' => $schema->boolean()
                ->description('Set to false to deactivate the field, or true to reactivate it.'),
        ];
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();

        if (! $user->ownsTeam($user->currentTeam)) {
            return (string) json_encode([
                'error' => 'Only team owners can update custom field definitions.',
            ]);
        }

        $entityType = (string) ($request['entity_type'] ?? '');
        $code = (string) ($request['code'] ?? '');

        if ($entityType === '' || $code === '') {
            return (string) json_encode(['error' => 'Both entity_type and code are required to identify the field.']);
        }

        $teamId = $user->currentTeam->getKey();
        $field = $this->resolveOwnedCustomField($teamId, $entityType, $code);

        if (! $field instanceof CustomField) {
            return (string) json_encode(['error' => "No custom field with code \"{$code}\" found on {$entityType}."]);
        }

        if ($field->isSystemDefined()) {
            return (string) json_encode(['error' => 'System-defined custom fields cannot be modified.']);
        }

        $newName = ($request['name'] ?? null) !== null ? (string) $request['name'] : null;
        $newActive = ($request['active'] ?? null) !== null ? (bool) $request['active'] : null;

        if ($newName === null && $newActive === null) {
            return (string) json_encode(['error' => 'Provide at least one of: name, active.']);
        }

        $actionData = [
            '_record_id' => $field->getKey(),
            '_model_class' => CustomField::class,
        ];

        $displayFields = [];

        if ($newName !== null) {
            $actionData['name'] = $newName;
            $displayFields[] = ['label' => 'Name', 'old' => $field->name, 'new' => $newName];
        }

        if ($newActive !== null) {
            $actionData['active'] = $newActive;
            $displayFields[] = [
                'label' => 'Active',
                'old' => $field->active ? 'Yes' : 'No',
                'new' => $newActive ? 'Yes' : 'No',
            ];
        }

        $displayData = [
            'title' => 'Update Custom Field',
            'summary' => "Update custom field \"{$field->name}\"",
            'fields' => $displayFields,
        ];

        $pending = resolve(PendingActionService::class)->createProposal(
            user: $user,
            conversationId: $this->resolveConversationId(),
            actionClass: UpdateCustomField::class,
            operation: PendingActionOperation::Update,
            entityType: 'custom_field',
            actionData: $actionData,
            displayData: $displayData,
        );

        return (string) json_encode([
            'type' => 'pending_action',
            'pending_action_id' => $pending->id,
            'action' => 'UpdateCustomField',
            'entity_type' => 'custom_field',
            'operation' => 'update',
            'data' => array_diff_key($pending->action_data, array_flip(['_record_id', '_model_class'])),
            'display' => $pending->display_data,
            'meta' => ['agent_should_stop' => true],
        ], JSON_PRETTY_PRINT);
    }
}
