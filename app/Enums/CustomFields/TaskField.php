<?php

declare(strict_types=1);

namespace App\Enums\CustomFields;

use App\Enums\CustomFieldType;
use App\Enums\OptionColor;
use App\Support\ChecklistHtml;
use Relaticle\CustomFields\Enums\CustomFieldWidth;

enum TaskField: string
{
    public const string DONE_STATUS = 'Done';

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
            self::STATUS => 'Status',
            self::PRIORITY => 'Priority',
            self::DESCRIPTION => 'Description',
            self::DUE_DATE => 'Due Date',
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
     * Get available options for this field
     *
     * @return array<int|string, string>|null Array of options for select/multi-select fields or null if not applicable
     */
    public function getOptions(): ?array
    {
        return match ($this) {
            self::STATUS => [
                'To do',
                'In progress',
                self::DONE_STATUS,
            ],
            self::PRIORITY => [
                'Low',
                'Medium',
                'High',
            ],
            default => null,
        };
    }

    /**
     * @return array<string, string>|null
     */
    public function getOptionColors(): ?array
    {
        return match ($this) {
            self::STATUS => [
                'To do' => OptionColor::Purple->value,
                'In progress' => OptionColor::Blue->value,
                self::DONE_STATUS => OptionColor::Green->value,
            ],
            self::PRIORITY => [
                'Low' => OptionColor::Gray->value,
                'Medium' => OptionColor::Amber->value,
                'High' => OptionColor::Red->value,
            ],
            default => null,
        };
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
