<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\CustomField;

use App\Models\CustomField;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\CustomFields\Models\Scopes\CustomFieldsActivableScope;
use Relaticle\CustomFields\Services\TenantContextService;

final class ListCustomFieldsTool implements Tool
{
    public function description(): string
    {
        return 'List the custom field definitions configured for this workspace, optionally filtered to one'
            .' entity type. Returns each field\'s entity_type, name, code, type, active status, whether it is'
            .' system-defined, and its options (for choice-type fields). Use this to answer "what custom fields'
            .' do I have" and to find a field\'s entity_type + code before proposing an update or adding options.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'entity_type' => $schema->string()
                ->description('Optional filter by entity: company, people, opportunity, task, or note.'),
        ];
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();
        $teamId = $user->currentTeam->getKey();

        $entityType = isset($request['entity_type']) && is_string($request['entity_type']) && $request['entity_type'] !== ''
            ? $request['entity_type']
            : null;

        $previousTenantId = TenantContextService::getCurrentTenantId();
        TenantContextService::setTenantId($teamId);

        try {
            $fields = CustomField::query()
                ->withoutGlobalScope(CustomFieldsActivableScope::class)
                ->where('tenant_id', $teamId)
                ->when($entityType !== null, fn (Builder $query) => $query->where('entity_type', $entityType))
                ->with(['options:id,custom_field_id,name'])
                ->orderBy('entity_type')
                ->orderBy('sort_order')
                ->get();

            $data = [];
            foreach ($fields as $field) {
                $data[] = [
                    'entity_type' => $field->entity_type,
                    'name' => $field->name,
                    'code' => $field->code,
                    'type' => $field->type,
                    'active' => (bool) $field->active,
                    'system_defined' => $field->isSystemDefined(),
                    'options' => $field->options->pluck('name')->values()->all(),
                ];
            }
        } finally {
            TenantContextService::setTenantId($previousTenantId);
        }

        return (string) json_encode([
            'custom_fields' => $data,
            'note' => 'System-defined fields cannot be modified from chat. To update or add options to a field, use its entity_type + code.',
        ], JSON_PRETTY_PRINT);
    }
}
