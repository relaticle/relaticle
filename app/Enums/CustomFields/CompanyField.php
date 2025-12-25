<?php

declare(strict_types=1);

namespace App\Enums\CustomFields;

use App\Enums\CustomFieldType;

enum CompanyField: string
{
    use CustomFieldTrait;

    /**
     * Ideal Customer Profile: Indicates whether the company is the most suitable customer for you
     */
    case ICP = 'icp';

    /**
     * Domains: Website domains of the company (system field)
     */
    case DOMAIN_NAME = 'domain_name';

    /**
     * LinkedIn: The LinkedIn profile URL of the company
     */
    case LINKEDIN = 'linkedin';

    public function getDisplayName(): string
    {
        return match ($this) {
            self::ICP => 'ICP',
            self::DOMAIN_NAME => 'Domains',
            self::LINKEDIN => 'LinkedIn',
        };
    }

    public function getFieldType(): string
    {
        return match ($this) {
            self::ICP => CustomFieldType::TOGGLE->value,
            self::DOMAIN_NAME, self::LINKEDIN => CustomFieldType::LINK->value,
        };
    }

    public function isSystemDefined(): bool
    {
        return match ($this) {
            self::DOMAIN_NAME => true,
            default => false,
        };
    }

    public function isListToggleableHidden(): bool
    {
        return match ($this) {
            self::ICP, self::DOMAIN_NAME => false,
            default => true,
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::ICP => 'Indicates whether this company is an Ideal Customer Profile',
            self::DOMAIN_NAME => 'Website domains of the company (e.g., example.com)',
            self::LINKEDIN => 'URL to the company\'s LinkedIn profile',
        };
    }

    public function allowsMultipleValues(): bool
    {
        return match ($this) {
            self::DOMAIN_NAME => true,
            default => false,
        };
    }

    public function isUniquePerEntityType(): bool
    {
        return match ($this) {
            self::DOMAIN_NAME => true,
            default => false,
        };
    }
}
