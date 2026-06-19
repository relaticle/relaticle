<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\CustomField;

use App\Actions\CustomFields\AddCustomFieldOptions;
use App\Actions\CustomFields\CreateCustomField;
use App\Models\CustomField;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Tools\Concerns\WithConversationContext;
use Relaticle\CustomFields\Models\Scopes\CustomFieldsActivableScope;
use Relaticle\CustomFields\Services\TenantContextService;

final class AddCustomFieldOptionsTool implements Tool
{
    use WithConversationContext;

    public function name(): string
    {
        return 'AddCustomFieldOptionsTool';
    }

    public function description(): string
    {
        return 'Propose adding new options to an existing choice-type custom field (select, multi-select, radio, checkbox-list, toggle-buttons). Admin-only. Returns a proposal for user approval.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('The custom field ID to add options to.')
                ->required(),
            'options' => $schema->array()
                ->items($schema->object([
                    'name' => $schema->string()->description('The option label.')->required(),
                ]))
                ->description('The new options to append to the field.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();

        if (! $user->ownsTeam($user->currentTeam)) {
            return (string) json_encode([
                'error' => 'Only team owners can manage custom field options.',
            ]);
        }

        $id = (string) ($request['id'] ?? '');

        if ($id === '') {
            return (string) json_encode(['error' => 'Field ID is required.']);
        }

        $options = is_array($request['options'] ?? null) ? $request['options'] : [];

        if ($options === []) {
            return (string) json_encode(['error' => 'At least one option must be provided.']);
        }

        $teamId = $user->currentTeam->getKey();
        $previousTenantId = TenantContextService::getCurrentTenantId();
        TenantContextService::setTenantId($teamId);

        try {
            $field = CustomField::query()
                ->withoutGlobalScope(CustomFieldsActivableScope::class)
                ->where('tenant_id', $teamId)
                ->find($id);
        } finally {
            TenantContextService::setTenantId($previousTenantId);
        }

        if (! $field instanceof CustomField) {
            return (string) json_encode(['error' => "Custom field with ID [{$id}] not found."]);
        }

        if (! in_array($field->type, CreateCustomField::CHOICE_TYPES, true)) {
            return (string) json_encode([
                'error' => "Field type \"{$field->type}\" does not support options. Only select, multi-select, radio, checkbox-list, and toggle-buttons fields can have options added.",
            ]);
        }

        $maxOptions = (int) config('chat.max_field_options', 50);

        $previousTenantId = TenantContextService::getCurrentTenantId();
        TenantContextService::setTenantId($teamId);

        try {
            $existingCount = $field->options()->withoutGlobalScopes()->count();
        } finally {
            TenantContextService::setTenantId($previousTenantId);
        }

        if (($existingCount + count($options)) > $maxOptions) {
            return (string) json_encode([
                'error' => "Adding {$maxOptions} more options would exceed the {$maxOptions} options limit for this field (currently has {$existingCount}).",
            ]);
        }

        $optionNames = array_map(
            static fn (mixed $o): string => is_array($o) ? (string) ($o['name'] ?? '') : (string) $o,
            $options,
        );

        $actionData = [
            '_record_id' => $field->getKey(),
            'options' => $options,
        ];

        $displayData = [
            'title' => 'Add Custom Field Options',
            'summary' => "Add options to \"{$field->name}\": ".implode(', ', $optionNames),
            'fields' => [
                ['label' => 'Field', 'value' => $field->name],
                ['label' => 'New Options', 'value' => implode(', ', $optionNames)],
            ],
        ];

        $pending = resolve(PendingActionService::class)->createProposal(
            user: $user,
            conversationId: $this->resolveConversationId(),
            actionClass: AddCustomFieldOptions::class,
            operation: PendingActionOperation::Create,
            entityType: 'custom_field',
            actionData: $actionData,
            displayData: $displayData,
        );

        return (string) json_encode([
            'type' => 'pending_action',
            'pending_action_id' => $pending->id,
            'action' => 'AddCustomFieldOptions',
            'entity_type' => 'custom_field',
            'operation' => 'create',
            'data' => $pending->action_data,
            'display' => $pending->display_data,
            'meta' => ['agent_should_stop' => true],
        ], JSON_PRETTY_PRINT);
    }
}
