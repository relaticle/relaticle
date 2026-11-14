<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use App\Enums\CrmEntity;

enum EmailFilter: string
{
    case Mailbox = 'mailbox';
    case From = 'from';
    case To = 'to';
    case Direction = 'direction';
    case HasAttachment = 'has_attachment';
    case SentAt = 'sent_at';
    case Record = 'record';
    case Category = 'category';

    /** @var array<string, CrmEntity> */
    public const array RECORD_WORDS = [
        'company' => CrmEntity::Company,
        'person' => CrmEntity::People,
        'opportunity' => CrmEntity::Opportunity,
    ];

    public function field(): string
    {
        return $this->value;
    }

    /** @return list<string> */
    public function operators(): array
    {
        return match ($this) {
            self::Mailbox, self::Direction, self::Category => ['$in'],
            self::From, self::To => ['$contains'],
            self::HasAttachment => ['$eq'],
            self::SentAt => ['$gte', '$lt'],
            self::Record => ['$in', '$starts_with'],
        };
    }

    /** @return list<string> */
    public function typedWords(): array
    {
        return match ($this) {
            self::From => ['from'],
            self::To => ['to'],
            self::HasAttachment => ['has'],
            self::SentAt => ['after', 'before'],
            self::Record => array_keys(self::RECORD_WORDS),
            self::Mailbox, self::Direction, self::Category => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Mailbox => __('filament/pages/email-inbox.filters.mailbox'),
            self::From => __('filament/pages/email-inbox.filters.from'),
            self::To => __('filament/pages/email-inbox.filters.to'),
            self::Direction => __('filament/pages/email-inbox.filters.direction'),
            self::HasAttachment => __('filament/pages/email-inbox.filters.has_attachment'),
            self::SentAt => __('filament/pages/email-inbox.filters.sent_at'),
            self::Record => __('filament/pages/email-inbox.filters.record'),
            self::Category => __('filament/pages/email-inbox.filters.category'),
        };
    }

    public static function forTypedWord(string $word): ?self
    {
        $word = mb_strtolower($word);

        return array_find(self::cases(), fn (self $filter): bool => in_array($word, $filter->typedWords(), true));
    }

    /** @return list<string> */
    public static function fields(): array
    {
        return array_map(fn (self $filter): string => $filter->field(), self::cases());
    }
}
