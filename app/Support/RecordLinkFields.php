<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CustomField;
use Relaticle\CustomFields\Models\CustomField as BaseCustomField;
use Relaticle\CustomFields\Models\CustomFieldRelationship;

/**
 * The link fields one entity renders, looked up once per request.
 *
 * A link field holds no value row, so every surface that reads a record's custom fields
 * has to ask which of them read the edge ledger. Asking per record turned that into a
 * query per row; the answer is the same for a whole page.
 */
final class RecordLinkFields
{
    /** @var array<string, list<BaseCustomField>> */
    private array $fields = [];

    /**
     * @return list<BaseCustomField>
     */
    public function forEntity(string $tenantId, string $entityType): array
    {
        return $this->fields[$tenantId.'|'.$entityType] ??= $this->load($tenantId, $entityType);
    }

    /**
     * @return list<BaseCustomField>
     */
    private function load(string $tenantId, string $entityType): array
    {
        $fields = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('entity_type', $entityType)
            ->active()
            ->get()
            ->all();

        return array_values(array_filter(
            $fields,
            static fn (BaseCustomField $field): bool => $field->relationshipDefinition() instanceof CustomFieldRelationship,
        ));
    }
}
