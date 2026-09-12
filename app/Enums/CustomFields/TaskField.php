<?php

declare(strict_types=1);

namespace App\Enums\CustomFields;

use App\Enums\CustomFieldType;
use Relaticle\CustomFields\Enums\CustomFieldWidth;

enum TaskField: string
{
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
                'Done',
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
     * Get color mapping for select field options
     *
     * 2024 sophisticated color psychology system - completely unique colors designed for
     * emotional resonance and semantic meaning. Based on latest color trends including
     * Digital Lavender, Mocha Mousse sophistication, and retro-futuristic aesthetics.
     *
     * @return array<string, string>|null Array of option => color mappings or null if not applicable
     */
    public function getOptionColors(): ?array
    {
        return match ($this) {
            self::STATUS => [
                'To do' => '#c4b5fd',       // Soft Periwinkle - Digital Lavender inspired calm potential
                'In progress' => '#0A80EA', // Professional Blue - Clear active progress state
                'Done' => '#2A9764',        // Success Green - Confident completion achievement
            ],
            self::PRIORITY => [
                'Low' => '#94a3b8',         // Sage Whisper - Natural earth tone, subtle presence
                'Medium' => '#d4a574',      // Burnished Gold - Mocha Mousse warmth & confidence
                'High' => '#dc2626',        // Crimson Velvet - Sophisticated urgent command
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
