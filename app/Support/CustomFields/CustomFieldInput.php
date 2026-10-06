<?php

declare(strict_types=1);

namespace App\Support\CustomFields;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Queries\Operand;
use App\Support\Media\RichContentAttachments;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelMarkdown\MarkdownRenderer;

final readonly class CustomFieldInput
{
    public function __construct(private CustomFieldOptionMap $optionMap, private MarkdownRenderer $markdown) {}

    /**
     * @param  array<array-key, mixed>  $customFields
     * @return array<array-key, mixed>
     */
    public function normalize(string $workspaceId, string $entityType, array $customFields, ?string $viewerZone = null): array
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

            $normalized[$code] = $this->normalizeValue($field, $value, $optionMap[(string) $code] ?? ['ids' => [], 'labels' => []], $viewerZone);
        }

        return $normalized;
    }

    /**
     * @param  array{ids: array<string, list<string>>, labels: list<string>}  $entry
     */
    private function normalizeValue(CustomField $field, mixed $value, array $entry, ?string $viewerZone): mixed
    {
        return match (CustomFieldType::from($field->type)) {
            CustomFieldType::SELECT,
            CustomFieldType::RADIO,
            CustomFieldType::TOGGLE_BUTTONS => $this->singleOption($field, $value, $entry),
            CustomFieldType::MULTI_SELECT,
            CustomFieldType::CHECKBOX_LIST => $this->optionList($field, $value, $entry),
            CustomFieldType::RICH_EDITOR => $this->richText($field, $value),
            CustomFieldType::DATE_TIME => $this->isBlankString($value) ? null : $this->utcDateTime($field, $value, $viewerZone),
            CustomFieldType::DATE => $this->isBlankString($value) ? null : $value,
            CustomFieldType::TEXT,
            CustomFieldType::NUMBER,
            CustomFieldType::EMAIL,
            CustomFieldType::PHONE,
            CustomFieldType::LINK,
            CustomFieldType::DOMAIN,
            CustomFieldType::TEXTAREA,
            CustomFieldType::CHECKBOX,
            CustomFieldType::TAGS_INPUT,
            CustomFieldType::COLOR_PICKER,
            CustomFieldType::TOGGLE,
            CustomFieldType::CURRENCY,
            CustomFieldType::FILE_UPLOAD,
            CustomFieldType::RECORD => $value,
        };
    }

    /**
     * @param  array{ids: array<string, list<string>>, labels: list<string>}  $entry
     */
    private function singleOption(CustomField $field, mixed $value, array $entry): mixed
    {
        if (! $this->optionMap->translates($field)) {
            return $value;
        }

        if ($this->isBlankString($value)) {
            return null;
        }

        if (! is_string($value) && ! is_int($value)) {
            $this->fail($field, __('validation.custom_field.single_option', ['field' => $field->name]));
        }

        return $this->resolveOption($field, (string) $value, $entry);
    }

    /**
     * @param  array{ids: array<string, list<string>>, labels: list<string>}  $entry
     */
    private function optionList(CustomField $field, mixed $value, array $entry): mixed
    {
        if (! $this->optionMap->translates($field)) {
            return $value;
        }

        if ($this->isBlankString($value)) {
            return null;
        }

        if (! is_array($value)) {
            $this->fail($field, __('validation.custom_field.option_list', ['field' => $field->name]));
        }

        return array_values(array_map(
            function (mixed $item) use ($field, $entry): string {
                if (! is_string($item) && ! is_int($item)) {
                    $this->fail($field, __('validation.custom_field.option_list', ['field' => $field->name]));
                }

                return $this->resolveOption($field, (string) $item, $entry);
            },
            $value,
        ));
    }

    /**
     * @param  array{ids: array<string, list<string>>, labels: list<string>}  $entry
     */
    private function resolveOption(CustomField $field, string $value, array $entry): string
    {
        $id = $this->optionMap->idFor($entry, $value);

        if ($id !== null) {
            return $id;
        }

        if ($this->optionMap->isAmbiguous($entry, $value)) {
            $this->fail($field, __('validation.custom_field.ambiguous_option', ['field' => $field->name, 'value' => $value]));
        }

        $this->fail($field, __('validation.custom_field.unknown_option', [
            'field' => $field->name,
            'value' => $value,
            'labels' => implode(', ', $entry['labels']),
        ]));
    }

    private function richText(CustomField $field, mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $html = str_starts_with(ltrim($value), '<') ? $value : $this->markdown->toHtml($value);

        return RichContentAttachments::forWorkspace((string) $field->tenant_id)->canonicalize($html);
    }

    private function utcDateTime(CustomField $field, mixed $value, ?string $viewerZone): mixed
    {
        if (! is_string($value) || Validator::make(['value' => $value], ['value' => ['date']])->fails()) {
            return $value;
        }

        if ($viewerZone !== null && Operand::lacksOffset($value)) {
            $this->fail($field, Operand::offsetRequired($field->code, $value, $viewerZone));
        }

        return Date::parse($value)->utc()->toDateTimeString();
    }

    private function isBlankString(mixed $value): bool
    {
        return is_string($value) && blank($value);
    }

    private function fail(CustomField $field, string $message): never
    {
        throw ValidationException::withMessages(["custom_fields.{$field->code}" => $message]);
    }
}
