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
            self::PHONE_NUMBER => CustomFieldType::PHONE->value,
            self::JOB_TITLE => CustomFieldType::TEXT->value,
            self::LINKEDIN => CustomFieldType::LINK->value,
        };
    }

    public function getDisplayName(): string
    {
        return match ($this) {
            self::EMAILS => __('enums/custom-fields.people.emails.name'),
            self::PHONE_NUMBER => __('enums/custom-fields.people.phone_number.name'),
            self::JOB_TITLE => __('enums/custom-fields.people.job_title.name'),
            self::LINKEDIN => __('enums/custom-fields.people.linkedin.name'),
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
