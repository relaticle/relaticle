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
    case TEXTAREA = 'textarea';
    case CHECKBOX = 'checkbox';
    case CHECKBOX_LIST = 'checkbox-list';
    case RADIO = 'radio';
    case RICH_EDITOR = 'rich-editor';
    case MARKDOWN_EDITOR = 'markdown-editor';
    case TAGS_INPUT = 'tags-input';
    case COLOR_PICKER = 'color-picker';
    case TOGGLE = 'toggle';
    case TOGGLE_BUTTONS = 'toggle-buttons';
    case CURRENCY = 'currency';
    case DATE = 'date';
    case DATE_TIME = 'date-time';
    case SELECT = 'select';
    case MULTI_SELECT = 'multi-select';
}
