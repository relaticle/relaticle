<?php

declare(strict_types=1);

namespace App\Listeners\CustomFields;

use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\CustomFieldRelationship;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Relaticle\CustomFields\Events\RelationshipLinkClosed;
use Relaticle\CustomFields\Events\RelationshipLinkCreated;

final readonly class LogLinkChangeListener
{
    public function handle(RelationshipLinkCreated|RelationshipLinkClosed $event): void
    {
        $link = $event->link;

        if (! $event->definition instanceof CustomFieldRelationship) {
            return;
        }

        $definition = $this->withSlots($event->definition);

        $fromRecord = $this->endRecord($event->record, $link->from_entity_type, $link->from_entity_id);
        $toRecord = $this->endRecord($event->record, $link->to_entity_type, $link->to_entity_id);

        if (! $fromRecord instanceof Model || ! $toRecord instanceof Model) {
            return;
        }

        $fromField = $definition->fromField instanceof CustomField ? $definition->fromField : null;
        $toField = $definition->toField instanceof CustomField ? $definition->toField : null;
        $opened = $event instanceof RelationshipLinkCreated;
        $causer = $event->context->actor;

        $this->log($fromRecord, $toRecord, $fromField, $toField, $link->to_entity_type, $definition, $opened, $causer);
        $this->log($toRecord, $fromRecord, $toField, $fromField, $link->from_entity_type, $definition, $opened, $causer);
    }

    private function log(
        Model $subject,
        Model $other,
        ?CustomField $field,
        ?CustomField $farField,
        string $farEntityType,
        CustomFieldRelationship $definition,
        bool $opened,
        ?Model $causer,
    ): void {
        // A one-way field has no slot on the far end, so that entry borrows the near
        // field's code and type: what it renders is that field's link, seen from here.
        $slot = $field ?? $farField;
        $value = ['value' => (string) $other->getKey(), 'label' => $this->displayName($other)];
        $empty = ['value' => null, 'label' => '—'];

        activity((string) config('activitylog.default_log_name'))
            ->performedOn($subject)
            ->causedBy($causer)
            ->withProperties([
                'custom_field_changes' => [[
                    'code' => $slot instanceof CustomField ? $slot->code : $definition->code,
                    'label' => $this->label($field, $farField, $farEntityType, $definition),
                    'type' => $slot instanceof CustomField ? $slot->type : CustomFieldType::RECORD->value,
                    'old' => $opened ? $empty : $value,
                    'new' => $opened ? $value : $empty,
                ]],
            ])
            ->event('custom_field_changes')
            ->log('custom_field_changes');
    }

    private function label(?CustomField $field, ?CustomField $farField, string $farEntityType, CustomFieldRelationship $definition): string
    {
        if ($field instanceof CustomField) {
            return $field->name;
        }

        if ($farField instanceof CustomField) {
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

    // A definition change raises the event without loading the slots, and a listener outside
    // a panel request has no ambient tenant to scope them with.
    private function withSlots(CustomFieldRelationship $definition): CustomFieldRelationship
    {
        return $definition->loadMissing([
            'fromField' => function (BelongsTo $query): void {
                $query->withoutGlobalScopes();
            },
            'toField' => function (BelongsTo $query): void {
                $query->withoutGlobalScopes();
            },
        ]);
    }

    private function endRecord(?Model $writer, string $entityType, int|string $entityId): ?Model
    {
        if ($writer instanceof Model && $writer->getMorphClass() === $entityType && (string) $writer->getKey() === (string) $entityId) {
            return $writer;
        }

        $modelClass = Relation::getMorphedModel($entityType) ?? $entityType;

        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            return null;
        }

        return $modelClass::query()->find($entityId);
    }
}
