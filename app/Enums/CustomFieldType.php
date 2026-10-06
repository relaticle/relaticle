<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ABOUTME: Maps to the field types available in the relaticle/custom-fields package
 * ABOUTME: Provides type-safe references to custom field types for maintainability
 */
enum CustomFieldType: string
{
    case TEXT = 'text';
    case NUMBER = 'number';
    case EMAIL = 'email';
    case PHONE = 'phone';
    case LINK = 'link';
    case DOMAIN = 'domain';
    case TEXTAREA = 'textarea';
    case CHECKBOX = 'checkbox';
    case CHECKBOX_LIST = 'checkbox-list';
    case RADIO = 'radio';
    case RICH_EDITOR = 'rich-editor';
    case TAGS_INPUT = 'tags-input';
    case COLOR_PICKER = 'color-picker';
    case TOGGLE = 'toggle';
    case TOGGLE_BUTTONS = 'toggle-buttons';
    case CURRENCY = 'currency';
    case DATE = 'date';
    case DATE_TIME = 'date-time';
    case SELECT = 'select';
    case MULTI_SELECT = 'multi-select';
    // Retired through config('custom-fields.field_type_configuration')->disabled(), not
    // deleted: stored rows still need a type name and a format when a schema lists them.
    case FILE_UPLOAD = 'file-upload';
    case RECORD = 'record';

    /** The value shape an agent must send when writing this field. */
    public function inputFormat(): string
    {
        return match ($this) {
            self::TEXT, self::TEXTAREA => 'string',
            self::NUMBER => 'numeric value',
            self::CURRENCY => 'numeric value (amount)',
            self::EMAIL => 'array of email strings',
            self::PHONE => 'array of phone strings',
            self::LINK => 'array of URL strings',
            self::DOMAIN => 'array of domain strings; a URL is reduced to its host',
            self::CHECKBOX, self::TOGGLE => 'boolean',
            self::SELECT, self::RADIO, self::TOGGLE_BUTTONS => 'option label or option ID',
            self::MULTI_SELECT, self::CHECKBOX_LIST => 'array of option labels or IDs',
            self::TAGS_INPUT => 'array of arbitrary string values',
            self::RICH_EDITOR => 'markdown, or HTML when the value starts with <; stored and returned as HTML',
            self::COLOR_PICKER => 'hex color string',
            self::DATE => 'ISO 8601 date',
            self::DATE_TIME => 'ISO 8601 datetime string; an offset is converted to UTC',
            self::RECORD => 'array of record IDs of the lookup entity; records must belong to this workspace',
            self::FILE_UPLOAD => 'read-only; the file-upload field type is no longer supported and cannot be written',
        };
    }

    /** The outline Heroicon shown before the field's label, matching the native detail rows. */
    public function icon(): string
    {
        return match ($this) {
            self::TEXT => 'heroicon-o-bars-3-bottom-left',
            self::NUMBER => 'heroicon-o-hashtag',
            self::EMAIL => 'heroicon-o-envelope',
            self::PHONE => 'heroicon-o-phone',
            self::LINK => 'heroicon-o-globe-alt',
            self::DOMAIN => 'heroicon-o-globe-alt',
            self::TEXTAREA => 'heroicon-o-document-text',
            self::CHECKBOX => 'heroicon-o-check-circle',
            self::CHECKBOX_LIST => 'heroicon-o-list-bullet',
            self::RADIO => 'heroicon-o-stop-circle',
            self::RICH_EDITOR => 'heroicon-o-pencil-square',
            self::TAGS_INPUT => 'heroicon-o-tag',
            self::COLOR_PICKER => 'heroicon-o-swatch',
            self::TOGGLE => 'heroicon-o-adjustments-horizontal',
            self::TOGGLE_BUTTONS => 'heroicon-o-view-columns',
            self::CURRENCY => 'heroicon-o-banknotes',
            self::DATE => 'heroicon-o-calendar',
            self::DATE_TIME => 'heroicon-o-clock',
            self::SELECT => 'heroicon-o-chevron-up-down',
            self::MULTI_SELECT => 'heroicon-o-queue-list',
            self::FILE_UPLOAD => 'heroicon-o-paper-clip',
            self::RECORD => 'heroicon-o-link',
        };
    }

    public function example(): mixed
    {
        return match ($this) {
            self::TEXT, self::TEXTAREA => 'Acme renewal',
            self::NUMBER => 42,
            self::CURRENCY => 15000.00,
            self::EMAIL => ['user@example.com'],
            self::PHONE => ['+1234567890'],
            self::LINK => ['https://example.com'],
            self::DOMAIN => ['example.com'],
            self::CHECKBOX, self::TOGGLE => true,
            self::SELECT, self::RADIO, self::TOGGLE_BUTTONS => 'In progress',
            self::MULTI_SELECT, self::CHECKBOX_LIST => ['Enterprise', 'EU'],
            self::TAGS_INPUT => ['priority', 'customer'],
            self::RICH_EDITOR => "## Notes\n- first call done",
            self::COLOR_PICKER => '#0A80EA',
            self::DATE => '2026-09-10',
            self::DATE_TIME => '2025-01-15T10:30:00Z',
            self::RECORD => ['01J...'],
            self::FILE_UPLOAD => null,
        };
    }

    public function filterMatching(): ?string
    {
        return match ($this) {
            self::EMAIL => 'in any letter case',
            self::LINK => 'in any letter case, with or without the scheme',
            self::DOMAIN => 'as a bare host or a full URL, with or without www',
            self::PHONE => 'in any format, and the operand needs a country code such as +1 415 555 0100',
            self::TAGS_INPUT => 'the exact stored value',
            self::DATE_TIME => 'a date without a time, such as 2026-10-01, as that whole day',
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public function filterExample(): array
    {
        return match ($this) {
            self::TEXT => ['$contains' => $this->example()],
            self::NUMBER, self::CURRENCY, self::DATE, self::DATE_TIME => ['$gte' => $this->example()],
            self::CHECKBOX, self::TOGGLE => ['$eq' => $this->example()],
            self::EMAIL, self::PHONE, self::LINK, self::DOMAIN, self::MULTI_SELECT, self::CHECKBOX_LIST, self::TAGS_INPUT => ['$has_any' => $this->example()],
            self::SELECT, self::RADIO, self::TOGGLE_BUTTONS => ['$in' => [$this->example()]],
            default => [],
        };
    }

    /** Whether the value is a list of URL-like strings that read as links. */
    public function isLinkList(): bool
    {
        return in_array($this, [self::LINK, self::DOMAIN], true);
    }

    /** Whether values are picked from the field's own options rather than typed freely. */
    public function isChoice(): bool
    {
        return match ($this) {
            self::SELECT, self::RADIO, self::TOGGLE_BUTTONS,
            self::MULTI_SELECT, self::CHECKBOX_LIST, self::TAGS_INPUT => true,
            default => false,
        };
    }
}
