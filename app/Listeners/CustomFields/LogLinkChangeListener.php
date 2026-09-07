<?php

declare(strict_types=1);

namespace App\Listeners\CustomFields;

use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Models\CustomField as HostCustomField;
use App\Models\CustomFieldLink;
use App\Models\CustomFieldRelationship;
use App\Support\LinkActorResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Relaticle\CustomFields\Events\RelationshipLinkClosed;
use Relaticle\CustomFields\Events\RelationshipLinkCreated;
use Relaticle\CustomFields\Models\CustomField as BaseCustomField;
use Relaticle\CustomFields\Models\Scopes\TenantScope;

/**
 * Writes the timeline entry both records of a relationship link deserve.
 *
 * A link is one row read from two sides, so one event is two entries: the person gains
 * a vendor and the company gains the person. Value changes flow through
 * CustomFieldValueObserver; a link field has no value row, so this is the only path
 * that can tell either timeline what happened.
 */
final readonly class LogLinkChangeListener
{
    public function __construct(
        private LinkActorResolver $actors,
    ) {}

    public function handle(RelationshipLinkCreated|RelationshipLinkClosed $event): void
    {
        $link = $event->link;
        $definition = $this->definition($link);

        if (! $definition instanceof CustomFieldRelationship) {
            return;
        }

        $fromRecord = $this->record($link->from_entity_type, $link->from_entity_id);
        $toRecord = $this->record($link->to_entity_type, $link->to_entity_id);

        if (! $fromRecord instanceof Model || ! $toRecord instanceof Model) {
            return;
        }

        $fromField = $this->field($definition->from_field_id);
        $toField = $this->field($definition->to_field_id);
        $opened = $event instanceof RelationshipLinkCreated;

        $this->log($fromRecord, $toRecord, $fromField, $toField, $link->to_entity_type, $definition, $opened);
        $this->log($toRecord, $fromRecord, $toField, $fromField, $link->from_entity_type, $definition, $opened);
    }

    /**
     * One side's entry: the field it reads its links through, and the record on the far
     * end as the value that came or went.
     */
    private function log(
        Model $subject,
        Model $other,
        ?BaseCustomField $field,
        ?BaseCustomField $farField,
        string $farEntityType,
        CustomFieldRelationship $definition,
        bool $opened,
    ): void {
        // A one-way field has no slot on the far end, so that entry borrows the near
        // field's code and type: what it renders is that field's link, seen from here.
        $slot = $field ?? $farField;
        $value = ['value' => (string) $other->getKey(), 'label' => $this->displayName($other)];
        $empty = ['value' => null, 'label' => '—'];

        activity((string) config('activitylog.default_log_name'))
            ->performedOn($subject)
            ->causedBy($this->actors->resolve())
            ->withProperties([
                'custom_field_changes' => [[
                    'code' => $slot instanceof BaseCustomField ? $slot->code : $definition->code,
                    'label' => $this->label($field, $farField, $farEntityType, $definition),
                    'type' => $slot instanceof BaseCustomField ? $slot->type : CustomFieldType::RECORD->value,
                    'old' => $opened ? $empty : $value,
                    'new' => $opened ? $value : $empty,
                ]],
            ])
            ->event('custom_field_changes')
            ->log('custom_field_changes');
    }

    /**
     * A paired relationship names both slots, so each side reads its own field name. A
     * one-way record field has no slot on the far end: that timeline is told which field,
     * on which entity, points at it, rather than a bare relationship code.
     */
    private function label(?BaseCustomField $field, ?BaseCustomField $farField, string $farEntityType, CustomFieldRelationship $definition): string
    {
        if ($field instanceof BaseCustomField) {
            return $field->name;
        }

        if ($farField instanceof BaseCustomField) {
            return __(':field (:entity)', ['field' => $farField->name, 'entity' => Str::headline($farEntityType)]);
        }

        return $definition->code;
    }

    private function displayName(Model $record): string
    {
        $column = CrmEntity::tryFrom($record->getMorphClass())?->titleColumn() ?? 'name';

        $name = $record->getAttribute($column);

        return is_string($name) && $name !== '' ? $name : (string) $record->getKey();
    }

    /**
     * The link names its definition and its ends by key, and every one of them is read
     * back here by that key alone: the write that raised the event already proved the
     * rows belong together, and a listener running outside a panel request has no
     * ambient tenant to scope them with.
     */
    private function definition(CustomFieldLink $link): ?CustomFieldRelationship
    {
        return CustomFieldRelationship::query()
            ->withoutGlobalScope(TenantScope::class)
            ->find($link->relationship_id);
    }

    private function field(int|string|null $fieldId): ?BaseCustomField
    {
        if ($fieldId === null) {
            return null;
        }

        return HostCustomField::query()->withoutGlobalScopes()->find($fieldId);
    }

    private function record(string $entityType, int|string $entityId): ?Model
    {
        $modelClass = Relation::getMorphedModel($entityType) ?? $entityType;

        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            return null;
        }

        return $modelClass::query()->find($entityId);
    }
}
