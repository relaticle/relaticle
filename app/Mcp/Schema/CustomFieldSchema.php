<?php

declare(strict_types=1);

namespace App\Mcp\Schema;

use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Relaticle\CustomFields\Services\ValidationService;
use stdClass;

/**
 * The writable custom fields of one entity, as the MCP schema publishes them.
 *
 * The read-path twin is {@see CustomFieldFilterSchema}. Per-type wording lives on
 * {@see CustomFieldType} so the chat tool schemas describe a field the same way.
 */
final readonly class CustomFieldSchema
{
    public function __construct(private CustomFieldFilterSchema $filterSchema) {}

    public function fields(User $user, CrmEntity $entity): stdClass
    {
        $workspaceId = $user->currentWorkspace->getKey();
        $cacheKey = McpSchemaCache::entitySchemaKey($workspaceId, $entity->value);

        return (object) Cache::remember($cacheKey, McpSchemaCache::TTL, function () use ($workspaceId, $entity): array {
            $fields = CustomField::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $workspaceId)
                ->where('entity_type', $entity->value)
                ->active()
                ->select('id', 'code', 'name', 'type', 'validation_rules')
                ->with(['options:id,custom_field_id,name'])
                ->get();

            return $this->format($fields);
        });
    }

    public function filterableFields(User $user, CrmEntity $entity): stdClass
    {
        return (object) $this->filterSchema->build($user, $entity->value);
    }

    /**
     * @param  Collection<int, CustomField>  $fields
     * @return array<string, array<string, mixed>>
     */
    private function format(Collection $fields): array
    {
        $result = [];

        foreach ($fields as $field) {
            $type = CustomFieldType::tryFrom($field->type);

            // A retired type keeps its stored rows but loses its case, so it has no
            // write vocabulary to publish. Skipping beats throwing the whole schema away.
            if (! $type instanceof CustomFieldType) {
                continue;
            }

            // validation_rules casts to ['required' => true], so a ['name' => 'required']
            // scan matches nothing and tells every agent no field was ever required.
            $required = resolve(ValidationService::class)->isRequired($field);

            $entry = [
                'name' => $field->name,
                'type' => $field->type,
                'required' => $required,
                'input_format' => $type->inputFormat(),
                'example' => $type->example(),
            ];

            if ($type->isChoice() && $field->options->isNotEmpty()) {
                $entry['options'] = $field->options->map(fn (CustomFieldOption $option): array => [
                    'id' => $option->id,
                    'label' => $option->name,
                ])->all();
            }

            $result[$field->code] = $entry;
        }

        return $result;
    }
}
