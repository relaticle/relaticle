<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\CustomFields\CompanyField;
use App\Enums\CustomFields\PeopleField;

enum RecordIdentity: string
{
    case Domains = 'domains';
    case Emails = 'emails';

    public function fieldCode(): string
    {
        return match ($this) {
            self::Domains => CompanyField::DOMAINS->value,
            self::Emails => PeopleField::EMAILS->value,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Domains => 'lucide-globe',
            self::Emails => 'lucide-at-sign',
        };
    }

    public function href(string $value): string
    {
        return match ($this) {
            self::Domains => preg_match('#^https?://#i', $value) === 1 ? $value : "https://{$value}",
            self::Emails => "mailto:{$value}",
        };
    }

    public function emptyLabel(): string
    {
        return match ($this) {
            self::Domains => __('filament/record-page.merge.no_domains'),
            self::Emails => __('filament/record-page.merge.no_emails'),
        };
    }

    public function searchPlaceholder(): string
    {
        return match ($this) {
            self::Domains => __('filament/record-page.merge.search_placeholder.company'),
            self::Emails => __('filament/record-page.merge.search_placeholder.people'),
        };
    }
}
