<?php

declare(strict_types=1);

namespace App\Enums\CustomFields;

use App\Enums\CustomFieldType;
use App\Enums\OptionColor;
use App\Models\CustomFieldOption;
use App\Support\ChecklistHtml;
use Illuminate\Support\Collection;
use Relaticle\CustomFields\Enums\CustomFieldWidth;

enum TaskField: string
{
    public const string DONE_LABEL_KEY = 'enums/custom-fields.task.status.options.done';

    public const string CHECKLIST_INPUT_FORMAT = 'bulleted markdown items "- [ ]" and "- [x]" become a checklist, while numbered items such as "1. [ ]" stay a numbered list';

    public const string CHECKLIST_HTML_READ_FORMAT = 'read back as '.ChecklistHtml::ITEM.' with data-checked="true" or "false"';

    use CustomFieldTrait;

    /**
     * Task status tracking
     */
    case STATUS = 'status';

    /**
     * Task priority level
     */
    case PRIORITY = 'priority';

    /**
     * Detailed task description
     */
    case DESCRIPTION = 'description';

    /**
     * Task due date and time
     */
    case DUE_DATE = 'due_date';

    /**
     * Get the display name for the field
     *
     * @return string The human-readable field name
     */
    public function getDisplayName(): string
    {
        return match ($this) {
            self::STATUS => __('enums/custom-fields.task.status.name'),
            self::PRIORITY => __('enums/custom-fields.task.priority.name'),
            self::DESCRIPTION => __('enums/custom-fields.task.description.name'),
            self::DUE_DATE => __('enums/custom-fields.task.due_date.name'),
        };
    }

    /**
     * Get the field type
     *
     * @return string The type of form control to use
     */
    public function getFieldType(): string
    {
        return match ($this) {
            self::STATUS, self::PRIORITY => CustomFieldType::SELECT->value,
            self::DESCRIPTION => CustomFieldType::RICH_EDITOR->value,
            self::DUE_DATE => CustomFieldType::DATE_TIME->value,
        };
    }

    /**
     * Get whether this field is system defined
     *
     * @return bool True if the field is system defined
     */
    public function isSystemDefined(): bool
    {
        return match ($this) {
            self::STATUS, self::PRIORITY => true,
            default => false,
        };
    }

    /**
     * Get whether the field is hidden in list toggle
     *
     * @return bool True if the field can be hidden in list view
     */
    public function isListToggleableHidden(): bool
    {
        return match ($this) {
            self::STATUS, self::PRIORITY, self::DUE_DATE => false,
            default => true,
        };
    }

    /**
     * Get the field width
     *
     * @return CustomFieldWidth The width of the field or null for default width
     */
    public function getWidth(): CustomFieldWidth
    {
        return match ($this) {
            self::STATUS, self::PRIORITY => CustomFieldWidth::_50,
            default => CustomFieldWidth::_100,
        };
    }

    /**
     * @return array<string, string>|null
     */
    public function optionKeyColors(): ?array
    {
        return match ($this) {
            self::STATUS => [
                'enums/custom-fields.task.status.options.to_do' => OptionColor::Purple->value,
                'enums/custom-fields.task.status.options.in_progress' => OptionColor::Blue->value,
                self::DONE_LABEL_KEY => OptionColor::Green->value,
            ],
            self::PRIORITY => [
                'enums/custom-fields.task.priority.options.low' => OptionColor::Gray->value,
                'enums/custom-fields.task.priority.options.medium' => OptionColor::Amber->value,
                'enums/custom-fields.task.priority.options.high' => OptionColor::Red->value,
            ],
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function doneNames(): array
    {
        return array_values(array_unique(array_map(
            fn (string $locale): string => __(self::DONE_LABEL_KEY, [], $locale),
            config('app.available_locales'),
        )));
    }

    /**
     * The done option of a status field is the earliest-created one named like a done label.
     *
     * @template TKey of array-key
     *
     * @param  Collection<TKey, CustomFieldOption>  $options
     */
    public static function doneOption(Collection $options): ?CustomFieldOption
    {
        return $options
            ->filter(fn (CustomFieldOption $option): bool => self::isDone($option->name))
            ->sortBy(fn (CustomFieldOption $option): string => (string) $option->getKey())
            ->first();
    }

    public static function isDone(string $name): bool
    {
        return in_array($name, self::doneNames(), true);
    }

    /**
     * Get field description (tooltip)
     *
     * @return string The field description
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::STATUS => 'Current status of the task',
            self::PRIORITY => 'Priority level for this task',
            self::DESCRIPTION => 'Detailed description of what needs to be done',
            self::DUE_DATE => 'When this task needs to be completed by',
        };
    }
}
