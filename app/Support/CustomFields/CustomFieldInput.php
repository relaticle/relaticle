<?php

declare(strict_types=1);

namespace App\Support\CustomFields;

use App\Enums\CustomFieldType;
use App\Enums\OptionMatching;
use App\Models\CustomField;
use App\Support\Media\RichContentAttachments;
use Illuminate\Validation\ValidationException;
use Relaticle\CustomFields\Facades\CustomFieldsType;
use Spatie\LaravelMarkdown\MarkdownRenderer;

final readonly class CustomFieldInput
{
    private const int MATCH_TIMEOUT_SECONDS = 3;

    public function __construct(
        private CustomFieldOptionMap $optionMap,
        private MarkdownRenderer $markdown,
        private OptionMatcher $optionMatcher,
    ) {}

    /**
     * @param  array<array-key, mixed>  $customFields
     * @return array<array-key, mixed>
     */
    public function normalize(string $workspaceId, string $entityType, array $customFields, OptionMatching $optionMatching): array
    {
        if ($customFields === []) {
            return [];
        }

        $fields = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $workspaceId)
            ->where('entity_type', $entityType)
            ->active()
            ->whereIn('code', array_keys($customFields))
            ->with('options')
            ->get()
            ->keyBy('code');

        $optionMap = $this->optionMap->fromFields($fields);
        $normalized = [];

        foreach ($customFields as $code => $value) {
            $field = $fields->get($code);

            if (! $field instanceof CustomField || $value === null) {
                $normalized[$code] = $value;

                continue;
            }

            $normalized[$code] = $this->normalizeValue($field, $value, $optionMap[(string) $code] ?? ['ids' => [], 'labels' => []], $optionMatching);
        }

        return $normalized;
    }

    /**
     * @param  array{ids: array<string, list<string>>, labels: list<string>}  $entry
     */
    private function normalizeValue(CustomField $field, mixed $value, array $entry, OptionMatching $optionMatching): mixed
    {
        return match (CustomFieldType::from($field->type)) {
            CustomFieldType::SELECT,
            CustomFieldType::RADIO,
            CustomFieldType::TOGGLE_BUTTONS => $this->singleOption($field, $value, $entry, $optionMatching),
            CustomFieldType::MULTI_SELECT,
            CustomFieldType::CHECKBOX_LIST => $this->optionList($field, $value, $entry, $optionMatching),
            CustomFieldType::RICH_EDITOR => $this->richText($field, $value),
            CustomFieldType::TEXT,
            CustomFieldType::NUMBER,
            CustomFieldType::EMAIL,
            CustomFieldType::PHONE,
            CustomFieldType::LINK,
            CustomFieldType::TEXTAREA,
            CustomFieldType::CHECKBOX,
            CustomFieldType::TAGS_INPUT,
            CustomFieldType::COLOR_PICKER,
            CustomFieldType::TOGGLE,
            CustomFieldType::CURRENCY,
            CustomFieldType::DATE,
            CustomFieldType::DATE_TIME,
            CustomFieldType::FILE_UPLOAD,
            CustomFieldType::RECORD => $value,
        };
    }

    /**
     * @param  array{ids: array<string, list<string>>, labels: list<string>}  $entry
     */
    private function singleOption(CustomField $field, mixed $value, array $entry, OptionMatching $optionMatching): mixed
    {
        if ($this->skipsOptionTranslation($field)) {
            return $value;
        }

        if ($this->isBlankString($value)) {
            return null;
        }

        if (! is_string($value) && ! is_int($value)) {
            $this->fail($field, __('validation.custom_field.single_option', ['field' => $field->name]));
        }

        return $this->resolveOption($field, (string) $value, $entry, $optionMatching);
    }

    /**
     * @param  array{ids: array<string, list<string>>, labels: list<string>}  $entry
     */
    private function optionList(CustomField $field, mixed $value, array $entry, OptionMatching $optionMatching): mixed
    {
        if ($this->skipsOptionTranslation($field)) {
            return $value;
        }

        if ($this->isBlankString($value)) {
            return null;
        }

        if (! is_array($value)) {
            $this->fail($field, __('validation.custom_field.option_list', ['field' => $field->name]));
        }

        $items = array_map(function (mixed $item) use ($field): string {
            if (! is_string($item) && ! is_int($item)) {
                $this->fail($field, __('validation.custom_field.option_list', ['field' => $field->name]));
            }

            return (string) $item;
        }, array_values($value));

        return $this->resolveOptionList($field, $items, $entry, $optionMatching);
    }

    /**
     * @param  array{ids: array<string, list<string>>, labels: list<string>}  $entry
     */
    private function resolveOption(CustomField $field, string $value, array $entry, OptionMatching $optionMatching): string
    {
        $id = $this->optionMap->idFor($entry, $value);

        if ($id !== null) {
            return $id;
        }

        if ($this->optionMap->isAmbiguous($entry, $value)) {
            $this->fail($field, __('validation.custom_field.ambiguous_option', ['field' => $field->name, 'value' => $value]));
        }

        $match = $this->closestOptions($field, [$value])[$value] ?? null;

        return $this->resolveOrFail($field, $value, $entry, $optionMatching, $match);
    }

    /**
     * @param  list<string>  $items
     * @param  array{ids: array<string, list<string>>, labels: list<string>}  $entry
     * @return list<string>
     */
    private function resolveOptionList(CustomField $field, array $items, array $entry, OptionMatching $optionMatching): array
    {
        $resolved = [];
        $unresolved = [];

        foreach ($items as $item) {
            $id = $this->optionMap->idFor($entry, $item);

            if ($id !== null) {
                $resolved[$item] = $id;

                continue;
            }

            if ($this->optionMap->isAmbiguous($entry, $item)) {
                $this->fail($field, __('validation.custom_field.ambiguous_option', ['field' => $field->name, 'value' => $item]));
            }

            $unresolved[] = $item;
        }

        $matches = $unresolved === []
            ? []
            : $this->closestOptions($field, array_values(array_unique($unresolved)));

        foreach ($unresolved as $item) {
            $resolved[$item] ??= $this->resolveOrFail($field, $item, $entry, $optionMatching, $matches[$item] ?? null);
        }

        return array_map(fn (string $item): string => $resolved[$item], $items);
    }

    /**
     * @param  array{ids: array<string, list<string>>, labels: list<string>}  $entry
     */
    private function resolveOrFail(CustomField $field, string $value, array $entry, OptionMatching $optionMatching, ?OptionMatch $match): string
    {
        if ($match instanceof OptionMatch && $optionMatching === OptionMatching::Apply) {
            return $match->key;
        }

        $replacements = [
            'field' => $field->name,
            'value' => $value,
            'labels' => implode(', ', $entry['labels']),
        ];

        $this->fail($field, $match instanceof OptionMatch
            ? __('validation.custom_field.unknown_option_suggestion', [...$replacements, 'suggestion' => $match->label])
            : __('validation.custom_field.unknown_option', $replacements));
    }

    /**
     * @param  list<string>  $values
     * @return array<array-key, OptionMatch>
     */
    private function closestOptions(CustomField $field, array $values): array
    {
        $options = [];

        foreach ($field->options as $option) {
            $options[(string) $option->getKey()] = (string) $option->name;
        }

        return $this->optionMatcher->match((string) $field->name, $options, $values, self::MATCH_TIMEOUT_SECONDS);
    }

    private function richText(CustomField $field, mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $html = str_starts_with(ltrim($value), '<') ? $value : $this->markdown->toHtml($value);

        return RichContentAttachments::forWorkspace((string) $field->tenant_id)->canonicalize($html);
    }

    private function isBlankString(mixed $value): bool
    {
        return is_string($value) && blank($value);
    }

    private function skipsOptionTranslation(CustomField $field): bool
    {
        $typeData = CustomFieldsType::getFieldType($field->type);

        return $typeData === null || $typeData->acceptsArbitraryValues || $field->lookup_type !== null;
    }

    private function fail(CustomField $field, string $message): never
    {
        throw ValidationException::withMessages(["custom_fields.{$field->code}" => $message]);
    }
}
