<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services\Tools;

use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\User;
use App\Support\CustomFields\CanonicalValue;
use App\Support\CustomFields\RecordNameResolver as CustomFieldRecordNames;
use App\Support\PlainText;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Relaticle\Chat\Support\RecordNameResolver;
use Relaticle\CustomFields\Data\RecordLinkPayload;
use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\CustomFields\Facades\CustomFieldsType;
use Relaticle\CustomFields\Models\CustomFieldLink;
use Relaticle\CustomFields\Models\CustomFieldOption;
use Relaticle\CustomFields\Models\CustomFieldRelationship;
use Relaticle\CustomFields\Models\CustomFieldValue;
use Relaticle\CustomFields\Services\Relationships\LinkReader;

final readonly class CustomFieldsDisplayFormatter
{
    /**
     * Build proposal-card rows for the chat UI from a validated custom_fields
     * payload. Values are already in their canonical form (option IDs for
     * choice fields, ISO strings for dates).
     *
     * @param  array<string, mixed>  $cleanFields
     * @return list<array{label: string, code: string, old?: string|null, new: string|null, type: string, values?: list<string>}>
     */
    public function format(User $user, string $entityType, array $cleanFields, ?Model $oldModel): array
    {
        if ($cleanFields === []) {
            return [];
        }

        $workspaceId = $user->currentWorkspace->getKey();
        $fields = CustomField::query()
            ->where('tenant_id', $workspaceId)
            ->where('entity_type', $entityType)
            ->active()
            ->whereIn('code', array_keys($cleanFields))
            ->with('options')
            ->get()
            ->keyBy('code');

        $timezone = $user->effectiveTimezone();
        $rows = [];
        foreach ($cleanFields as $code => $proposedValue) {
            $field = $fields->get($code);
            if (! $field instanceof CustomField) {
                continue;
            }

            $newValue = $this->storedForm($field, $proposedValue);
            $definition = $field->relationshipDefinition();

            if ($definition instanceof CustomFieldRelationship) {
                $rows[] = $this->linkRow($user, $field, $definition, (string) $code, $newValue, $oldModel);

                continue;
            }

            $row = $this->valueRow($field, (string) $code, $newValue, $timezone);

            if ($oldModel instanceof Model) {
                $oldValue = $this->lookupCurrentValue($field, $oldModel);
                $row['old'] = $oldValue !== null ? $this->renderValue($field, $oldValue, $timezone) : null;
                // Raw values ride along so the no-op check compares stored data,
                // not rendered labels (two options can share a label). The write
                // base strips them before the row is persisted or displayed.
                $row['_oldValue'] = $this->comparable($field, $oldValue);
                $row['_newValue'] = $this->comparable($field, $newValue);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Display rows for the values a record already has stored, one per given
     * field, in the order the fields are given. Fields the record holds no
     * value for are skipped: a display block is a summary, not a form.
     *
     * The sibling of format(): that one renders a *proposed* payload, this one
     * renders a *persisted* record, and both share the same value rendering so
     * a proposal card and a record card never disagree about a value.
     *
     * @param  list<CustomField>  $fields  already scoped to the record's tenant, with options loaded
     * @param  int  $valueLimit  characters kept per free-text value, after whitespace is squeezed onto one line
     * @return list<array{label: string, code: string, value: string, type: string, values?: list<string>}>
     */
    public function formatStored(Model $model, array $fields, int $valueLimit, string $timezone): array
    {
        if ($fields === [] || ! $model->relationLoaded('customFieldValues')) {
            return [];
        }

        /** @var Collection<int, CustomFieldValue> $storedValues */
        $storedValues = $model->getRelation('customFieldValues');
        $byFieldId = $storedValues->keyBy('custom_field_id');

        $rows = [];

        foreach ($fields as $field) {
            $definition = $field->relationshipDefinition();

            if ($definition instanceof CustomFieldRelationship) {
                $linkRow = $this->storedLinkRow($model, $field, $definition);

                if ($linkRow !== null) {
                    $rows[] = $linkRow;
                }

                continue;
            }

            $stored = $byFieldId->get($field->getKey());

            if (! $stored instanceof CustomFieldValue) {
                continue;
            }

            $row = $this->storedValueRow($field, $stored, $valueLimit, $timezone);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The chip/link list behind a typed value, mirroring what format() attaches
     * to a proposal row so a record card and a proposal card render the same
     * field the same way. Null means the type has no list and the joined
     * `value` string is the whole story.
     *
     * @return list<string>|null
     */
    private function storedValues(CustomField $field, mixed $value, ?FieldDataType $dataType): ?array
    {
        if (CustomFieldType::tryFrom($field->type)?->isLinkList()) {
            return is_array($value) ? $this->optionNames($field, $value) : null;
        }

        if ($field->type === CustomFieldType::RECORD->value) {
            return $this->storedRecordNames($field, $value);
        }

        if ($dataType === FieldDataType::MULTI_CHOICE) {
            return is_array($value) ? $this->optionNames($field, $value) : null;
        }

        if ($dataType === FieldDataType::SINGLE_CHOICE) {
            $name = $this->renderSingleChoice($field, $value);

            return $name === null || $name === '' ? null : [$name];
        }

        return null;
    }

    /**
     * `json_value` is cast to a Collection, which every is_array() branch in
     * this class would miss and stringify into raw JSON. Every read of a stored
     * column goes through here: formatStored() for the record card, and
     * lookupCurrentValue() for the old side of a proposal diff.
     */
    private function plainValue(mixed $value): mixed
    {
        return $value instanceof Collection ? $value->all() : $value;
    }

    /**
     * A display block is a summary that the tool result carries forever, so a
     * stored value is always squeezed onto one line. The length cap is applied
     * to free text ONLY: that is the unbounded type (a rich-editor field holds
     * kilobytes of prose), while a capped URL is a broken href and a capped
     * option list is a chip cut in half.
     *
     * format() deliberately does neither: a proposal diff has to show exactly
     * what is about to be written.
     */
    private function condense(string $value, ?int $limit): string
    {
        $oneLine = Str::squish($value);

        return $limit === null ? $oneLine : Str::limit($oneLine, $limit);
    }

    /**
     * A link field's proposal row: the records it will point at, as chips, beside the
     * ones it points at now. A record proposed earlier in this turn resolves to its
     * proposed name, so the card never shows an approval the user was not told about.
     *
     * @return array{label: string, code: string, new: string|null, type: string, values: list<string>, old?: string|null, _oldValue?: mixed, _newValue?: mixed}
     */
    private function linkRow(User $user, CustomField $field, CustomFieldRelationship $definition, string $code, mixed $newValue, ?Model $oldModel): array
    {
        $newIds = RecordLinkPayload::fromValue($newValue)->ids;
        $names = $this->recordNames($user, $definition, $field, $newIds);

        $row = [
            'label' => $field->name,
            'code' => $code,
            'new' => $names === [] ? null : implode(', ', $names),
            'type' => 'badges',
            'values' => $names,
        ];

        if (! $oldModel instanceof Model) {
            return $row;
        }

        // The current edges, not a value row: a link field has none. This is the same
        // read the record page makes, so both sides of the diff agree.
        $oldIds = $this->currentLinkIds($oldModel, $field);
        $oldNames = $this->recordNames($user, $definition, $field, $oldIds);

        $row['old'] = $oldNames === [] ? null : implode(', ', $oldNames);
        $row['_oldValue'] = $oldIds;
        $row['_newValue'] = $newIds;

        return $row;
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return list<string>
     */
    private function recordNames(User $user, CustomFieldRelationship $definition, CustomField $field, array $ids): array
    {
        $entityType = $definition->targetEntityTypeFor($field);
        $modelClass = Relation::getMorphedModel($entityType);

        if ($modelClass === null || ! is_subclass_of($modelClass, Model::class)) {
            return [];
        }

        return resolve(RecordNameResolver::class)->labels(
            $ids,
            $modelClass,
            $user->currentWorkspace,
            CrmEntity::tryFrom($entityType)?->titleColumn() ?? 'name',
        );
    }

    /**
     * @return array<int, int|string>
     */
    private function currentLinkIds(Model $model, CustomField $field): array
    {
        if (! method_exists($model, 'getCustomFieldValue')) {
            return [];
        }

        $ids = $model->getCustomFieldValue($field);

        return is_array($ids) ? array_values($ids) : [];
    }

    /**
     * The names a record's links already carry, read from the relations the caller
     * loaded for the whole page. A display block is a summary, so an unloaded relation
     * skips the field rather than paying a query per row for it.
     *
     * @return list<string>
     */
    private function loadedLinkNames(Model $model, CustomField $field, CustomFieldRelationship $definition): array
    {
        if (! $model->relationLoaded('outgoingLinks') || ! $model->relationLoaded('incomingLinks')) {
            return [];
        }

        $names = [];

        foreach (resolve(LinkReader::class)->orderedLinksFor($model, $definition, $definition->readDirectionFor($field)) as $link) {
            $relation = $this->farEndRelation($link, $model);

            if (! $link->relationLoaded($relation)) {
                return [];
            }

            $far = $link->getRelation($relation);

            if (! $far instanceof Model) {
                continue;
            }

            $column = CrmEntity::tryFrom($far->getMorphClass())?->titleColumn() ?? 'name';
            $name = $far->getAttribute($column);

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function farEndRelation(CustomFieldLink $link, Model $model): string
    {
        $isFromEnd = $link->from_entity_type === $model->getMorphClass()
            && (string) $link->from_entity_id === (string) $model->getKey();

        return $isFromEnd ? 'toEntity' : 'fromEntity';
    }

    private function renderValue(CustomField $field, mixed $value, string $timezone): ?string
    {
        if (in_array($value, [null, '', []], true)) {
            return null;
        }

        if ($field->type === CustomFieldType::RECORD->value) {
            return $this->renderRecords($field, $value);
        }

        if ($field->type === CustomFieldType::RICH_EDITOR->value) {
            return PlainText::fromHtml((string) $value);
        }

        $dataType = CustomFieldsType::getFieldType($field->type)?->dataType;

        return match ($dataType) {
            FieldDataType::SINGLE_CHOICE => $this->renderSingleChoice($field, $value),
            FieldDataType::MULTI_CHOICE => $this->renderMultiChoice($field, $value),
            FieldDataType::DATE => $this->toDate($value)->isoFormat('MMM D, YYYY'),
            FieldDataType::DATE_TIME => $this->toDate($value)->setTimezone($timezone)->isoFormat('MMM D, YYYY'),
            FieldDataType::TEXT => trim(strip_tags((string) $value)),
            FieldDataType::BOOLEAN => $value ? 'Yes' : 'No',
            default => is_array($value) ? implode(', ', array_map(strval(...), $value)) : (string) $value,
        };
    }

    private function renderSingleChoice(CustomField $field, mixed $value): ?string
    {
        $option = $field->options->firstWhere('id', (string) $value);

        return $option instanceof CustomFieldOption ? $option->name : (string) $value;
    }

    private function renderRecords(CustomField $field, mixed $value): string
    {
        return implode(', ', $this->storedRecordNames($field, $value));
    }

    /** @return list<string> */
    private function storedRecordNames(CustomField $field, mixed $value): array
    {
        return array_map(
            fn (array $record): string => $record['name'] ?? $record['id'],
            resolve(CustomFieldRecordNames::class)->resolve((string) $field->targetEntityType(), $value),
        );
    }

    private function renderMultiChoice(CustomField $field, mixed $value): string
    {
        if (! is_array($value)) {
            return (string) $value;
        }

        return implode(', ', $this->optionNames($field, $value));
    }

    private function displayType(CustomField $field, ?FieldDataType $dataType): string
    {
        if (CustomFieldType::tryFrom($field->type)?->isLinkList()) {
            return 'link';
        }

        return match ($dataType) {
            FieldDataType::SINGLE_CHOICE, FieldDataType::MULTI_CHOICE => 'badges',
            FieldDataType::BOOLEAN => 'boolean',
            default => 'text',
        };
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @return list<string>
     */
    private function optionNames(CustomField $field, array $ids): array
    {
        $byId = $field->options->keyBy('id');

        return array_values(array_map(function (mixed $id) use ($byId): string {
            $option = $byId->get((string) $id);

            return $option instanceof CustomFieldOption ? $option->name : (string) $id;
        }, $ids));
    }

    private function toDate(mixed $value): CarbonImmutable
    {
        return $value instanceof DateTimeInterface ? Date::instance($value) : Date::parse((string) $value);
    }

    private function storedForm(CustomField $field, mixed $value): mixed
    {
        return in_array($field->type, [CustomFieldType::LINK->value, CustomFieldType::DOMAIN->value, CustomFieldType::PHONE->value], true)
            ? $this->comparable($field, $value)
            : $value;
    }

    private function comparable(CustomField $field, mixed $value): mixed
    {
        return match (true) {
            is_string($value) => CanonicalValue::of($field, $value),
            is_array($value) => collect($value)
                ->map(fn (mixed $item): mixed => $this->comparable($field, $item))
                ->unique(strict: true)
                ->values()
                ->all(),
            default => $value,
        };
    }

    /**
     * Read the current value of a custom field on a model via the
     * directly-loaded customFieldValues relation.
     *
     * Unwrapped through plainValue() for the same reason formatStored() is: a
     * Collection reaching renderValue() stringifies to its own JSON, which
     * would print `["01K4...","01K5..."]` on the OLD side of a multi-value diff
     * while the NEW side, fed a plain array from the payload, prints the option
     * names. Both sides of one card have to agree.
     */
    private function lookupCurrentValue(CustomField $field, Model $model): mixed
    {
        if (! method_exists($model, 'customFieldValues')) {
            return null;
        }

        $row = $model->customFieldValues()
            ->where('custom_field_id', $field->getKey())
            ->first();

        if (! $row instanceof CustomFieldValue) {
            return null;
        }

        $column = CustomFieldValue::getValueColumn($field->type);

        return $this->plainValue($row->{$column});
    }

    /**
     * The proposed value of one non-link field, rendered once for the card heading and,
     * where the field is option-backed, again as the list of labels the card draws.
     *
     * @return array{label: string, code: string, new: string|null, type: string, values?: list<string>}
     */
    private function valueRow(CustomField $field, string $code, mixed $newValue, string $timezone): array
    {
        $dataType = CustomFieldsType::getFieldType($field->type)?->dataType;

        $row = [
            'label' => $field->name,
            'code' => $code,
            'new' => $this->renderValue($field, $newValue, $timezone),
            'type' => $this->displayType($field, $dataType),
        ];

        if ($field->type === CustomFieldType::RECORD->value) {
            $row['values'] = $this->storedRecordNames($field, $newValue);
        } elseif ($dataType === FieldDataType::MULTI_CHOICE && is_array($newValue)) {
            $row['values'] = $this->optionNames($field, $newValue);
        }

        if ($dataType === FieldDataType::SINGLE_CHOICE) {
            $name = $this->renderSingleChoice($field, $newValue);
            $row['values'] = $name === null || $name === '' ? [] : [$name];
        }

        return $row;
    }

    /**
     * The records a link field holds, as the badges row a record card draws. Null when
     * the record links nothing: a display block is a summary, not a form.
     *
     * @return array{label: string, code: string, value: string, type: string, values: list<string>}|null
     */
    private function storedLinkRow(Model $model, CustomField $field, CustomFieldRelationship $definition): ?array
    {
        $names = $this->loadedLinkNames($model, $field, $definition);

        if ($names === []) {
            return null;
        }

        return [
            'label' => $field->name,
            'code' => $field->code,
            'value' => implode(', ', $names),
            'type' => 'badges',
            'values' => $names,
        ];
    }

    /**
     * One stored value, condensed to the width a card gives it. Null when the field
     * renders to nothing, which a summary leaves out rather than showing as blank.
     *
     * @return array{label: string, code: string, value: string, type: string, values?: list<string>}|null
     */
    private function storedValueRow(CustomField $field, CustomFieldValue $stored, int $valueLimit, string $timezone): ?array
    {
        $raw = $this->plainValue($stored->{CustomFieldValue::getValueColumn($field->type)});
        $rendered = $this->renderValue($field, $raw, $timezone);

        if ($rendered === null) {
            return null;
        }

        $dataType = CustomFieldsType::getFieldType($field->type)?->dataType;
        $type = $this->displayType($field, $dataType);
        $value = $this->condense($rendered, $type === 'text' ? $valueLimit : null);

        if ($value === '') {
            return null;
        }

        $row = [
            'label' => $field->name,
            'code' => $field->code,
            'value' => $value,
            'type' => $type,
        ];

        $values = $this->storedValues($field, $raw, $dataType);

        if ($values !== null) {
            $row['values'] = $values;
        }

        return $row;
    }
}
