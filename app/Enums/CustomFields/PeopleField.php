<?php

declare(strict_types=1);

namespace App\Enums\CustomFields;

use App\Enums\CustomFieldType;

/**
 * People custom field codes
 */
enum PeopleField: string
{
    use CustomFieldTrait;

    case EMAILS = 'emails';
    case PHONE_NUMBER = 'phone_number';
    case JOB_TITLE = 'job_title';
    case LINKEDIN = 'linkedin';

    public function getFieldType(): string
    {
        return match ($this) {
            self::EMAILS => CustomFieldType::EMAIL->value,
            self::PHONE_NUMBER, self::JOB_TITLE => CustomFieldType::TEXT->value,
            self::LINKEDIN => CustomFieldType::LINK->value,
        };
    }

    public function getDisplayName(): string
    {
        return match ($this) {
            self::EMAILS => 'Emails',
            self::PHONE_NUMBER => 'Phone Number',
            self::JOB_TITLE => 'Job Title',
            self::LINKEDIN => 'LinkedIn',
        };
    }

    public function isListToggleableHidden(): bool
    {
        return match ($this) {
            self::JOB_TITLE, self::EMAILS => false,
            default => true,
        };
    }

    public function isSystemDefined(): bool
    {
        return match ($this) {
            self::EMAILS => true,
            default => false,
        };
    }

    public function allowsMultipleValues(): bool
    {
        return match ($this) {
            self::EMAILS => true,
            default => false,
        };
    }

    public function isUniquePerEntityType(): bool
    {
        return match ($this) {
            self::EMAILS => true,
            default => false,
        };
    }
}
