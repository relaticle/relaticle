<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Data;

use App\Enums\CrmEntity;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Queries\FilterErrors;
use App\Queries\Operand;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Relaticle\EmailIntegration\Enums\EmailCategory;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFilter;

final readonly class ConversationFilters
{
    /**
     * @param  list<string>  $mailboxIds
     * @param  list<string>  $recordIds
     * @param  list<EmailCategory>  $categories
     */
    public function __construct(
        public array $mailboxIds = [],
        public ?EmailDirection $direction = null,
        public ?bool $hasAttachment = null,
        public ?CarbonImmutable $sentAfter = null,
        public ?CarbonImmutable $sentBefore = null,
        public ?CrmEntity $recordType = null,
        public array $recordIds = [],
        public ?string $recordName = null,
        public array $categories = [],
        public ?string $from = null,
        public ?string $to = null,
        public ?string $search = null,
    ) {}

    public static function forRecord(Company|People|Opportunity $record, ?string $search = null): self
    {
        return new self(
            recordType: CrmEntity::from($record->getMorphClass()),
            recordIds: [(string) $record->getKey()],
            search: filled($search) ? $search : null,
        );
    }

    /**
     * @param  array<array-key, mixed>  $tree
     *
     * @throws ValidationException
     */
    public static function fromTree(array $tree, string $viewerZone): self
    {
        if ($tree !== [] && array_is_list($tree)) {
            throw FilterErrors::at('filter', __('validation.filter.not_object'));
        }

        $filters = new self;

        foreach ($tree as $field => $condition) {
            $filter = EmailFilter::tryFrom((string) $field) ?? throw FilterErrors::at(
                "filter.{$field}",
                __('validation.filter.unknown_name', ['name' => $field, 'available' => implode(', ', EmailFilter::fields())]),
            );

            try {
                $filters = $filters->with(self::parse($filter, $condition, $viewerZone));
            } catch (ValidationException $exception) {
                throw FilterErrors::prefix($exception, "filter.{$filter->field()}");
            }
        }

        return $filters;
    }

    /**
     * Known `word:value` tokens become filters. Unknown words and invalid values stay in the search.
     */
    public function withTyped(string $input, string $viewerZone): self
    {
        preg_match_all('/(\p{L}+):(?:"([^"]*)"|(\S+))|"[^"]*"?|\S+/u', $input, $tokens, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        $changes = [];
        $words = [];

        foreach ($tokens as $token) {
            $typed = $token[1] === null ? null : $this->typed($token[1], $token[2] ?? $token[3] ?? '', $viewerZone);

            if ($typed === null) {
                $words[] = (string) $token[0];

                continue;
            }

            $changes = [...$changes, ...$typed];
        }

        $search = implode(' ', $words);

        return $this->with([...$changes, 'search' => $search === '' ? null : $search]);
    }

    /** @return array<string, array<string, mixed>> */
    public function toTree(): array
    {
        $values = static fn (array $cases): array => array_map(static fn (BackedEnum $case): string|int => $case->value, $cases);

        return array_filter([
            EmailFilter::Mailbox->field() => $this->mailboxIds === [] ? null : ['$in' => $this->mailboxIds],
            EmailFilter::From->field() => $this->from === null ? null : ['$contains' => $this->from],
            EmailFilter::To->field() => $this->to === null ? null : ['$contains' => $this->to],
            EmailFilter::Direction->field() => $this->direction instanceof EmailDirection ? ['$in' => [$this->direction->value]] : null,
            EmailFilter::HasAttachment->field() => $this->hasAttachment === null ? null : ['$eq' => $this->hasAttachment],
            EmailFilter::SentAt->field() => array_filter([
                '$gte' => $this->sentAfter?->toIso8601String(),
                '$lt' => $this->sentBefore?->toIso8601String(),
            ]) ?: null,
            EmailFilter::Record->field() => $this->recordTree(),
            EmailFilter::Category->field() => $this->categories === [] ? null : ['$in' => $values($this->categories)],
        ], static fn (?array $condition): bool => $condition !== null);
    }

    /** The words that type this filter back into the search box, or null when it has none. */
    public function typedFor(EmailFilter $filter, string $viewerZone): ?string
    {
        $quote = static fn (string $value): string => preg_match('/\s/u', $value) === 1 ? "\"{$value}\"" : $value;
        $day = static function (CarbonImmutable $instant) use ($viewerZone): string {
            $local = $instant->setTimezone($viewerZone);

            return $local->eq($local->startOfDay()) ? $local->format('Y-m-d') : $instant->toIso8601String();
        };
        $word = array_search($this->recordType, EmailFilter::RECORD_WORDS, true);

        $typed = match ($filter) {
            EmailFilter::From => $this->from === null ? null : 'from:'.$quote($this->from),
            EmailFilter::To => $this->to === null ? null : 'to:'.$quote($this->to),
            EmailFilter::HasAttachment => $this->hasAttachment === true ? 'has:attachment' : null,
            EmailFilter::SentAt => implode(' ', array_filter([
                $this->sentAfter instanceof CarbonImmutable ? 'after:'.$day($this->sentAfter) : null,
                $this->sentBefore instanceof CarbonImmutable ? 'before:'.$day($this->sentBefore) : null,
            ])),
            EmailFilter::Record => is_string($word) && $this->recordName !== null && $this->recordIds === [] ? "{$word}:".$quote($this->recordName) : null,
            EmailFilter::Mailbox, EmailFilter::Direction, EmailFilter::Category => null,
        };

        return $typed === '' ? null : $typed;
    }

    /** @param  array<string, mixed>  $changes */
    private function with(array $changes): self
    {
        $values = [...get_object_vars($this), ...$changes];

        return new self(
            mailboxIds: $values['mailboxIds'],
            direction: $values['direction'],
            hasAttachment: $values['hasAttachment'],
            sentAfter: $values['sentAfter'],
            sentBefore: $values['sentBefore'],
            recordType: $values['recordType'],
            recordIds: $values['recordIds'],
            recordName: $values['recordName'],
            categories: $values['categories'],
            from: $values['from'],
            to: $values['to'],
            search: $values['search'],
        );
    }

    /** @return array<string, array<string, mixed>>|null */
    private function recordTree(): ?array
    {
        $word = array_search($this->recordType, EmailFilter::RECORD_WORDS, true);

        if (! is_string($word)) {
            return null;
        }

        return [$word => array_filter([
            '$in' => $this->recordIds === [] ? null : $this->recordIds,
            '$starts_with' => $this->recordName,
        ], static fn (mixed $operand): bool => $operand !== null)];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function parse(EmailFilter $filter, mixed $condition, string $viewerZone): array
    {
        if ($filter === EmailFilter::Record) {
            return self::record($condition);
        }

        $operands = self::operands($filter->field(), $filter->operators(), $condition);

        return match ($filter) {
            EmailFilter::Mailbox => ['mailboxIds' => self::ids($filter->field(), $operands['$in'])],
            EmailFilter::Direction => ['direction' => self::onlyDirection(self::cases($filter->field(), $operands['$in'], EmailDirection::class))],
            EmailFilter::Category => ['categories' => self::cases($filter->field(), $operands['$in'], EmailCategory::class)],
            EmailFilter::From => ['from' => self::text($filter->field(), '$contains', $operands['$contains'])],
            EmailFilter::To => ['to' => self::text($filter->field(), '$contains', $operands['$contains'])],
            EmailFilter::HasAttachment => ['hasAttachment' => Operand::boolean($operands['$eq'])
                ?? throw FilterErrors::operand($filter->field(), '$eq', __('validation.filter.expected.boolean'))],
            EmailFilter::SentAt => self::sentAt($operands, $viewerZone),
        };
    }

    /**
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function operands(string $name, array $allowed, mixed $condition): array
    {
        if (! is_array($condition) || $condition === [] || array_is_list($condition)) {
            throw FilterErrors::at('', __('validation.filter.operator_object', ['name' => $name, 'operator' => $allowed[0]]));
        }

        foreach (array_keys($condition) as $operator) {
            $operator = (string) $operator;

            if (! str_starts_with($operator, '$')) {
                throw FilterErrors::sigil($operator, $operator);
            }

            if (! in_array($operator, $allowed, true)) {
                throw FilterErrors::at($operator, __('validation.filter.unsupported_operator', [
                    'name' => $name,
                    'operator' => $operator,
                    'supported' => implode(', ', $allowed),
                ]));
            }
        }

        /** @var array<string, mixed> $condition */
        return $condition;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function record(mixed $condition): array
    {
        $words = array_keys(EmailFilter::RECORD_WORDS);

        if (! is_array($condition) || count($condition) !== 1 || ! in_array(array_key_first($condition), $words, true)) {
            throw FilterErrors::at('', __('validation.filter.email_record', ['types' => implode(', ', $words)]));
        }

        $word = (string) array_key_first($condition);
        $name = EmailFilter::Record->field().' '.$word;

        try {
            $operands = self::operands($name, EmailFilter::Record->operators(), $condition[$word]);

            return [
                'recordType' => EmailFilter::RECORD_WORDS[$word],
                'recordIds' => array_key_exists('$in', $operands) ? self::ids($name, $operands['$in']) : [],
                'recordName' => array_key_exists('$starts_with', $operands) ? self::text($name, '$starts_with', $operands['$starts_with']) : null,
            ];
        } catch (ValidationException $exception) {
            throw FilterErrors::prefix($exception, $word);
        }
    }

    /**
     * @return list<string>
     *
     * @throws ValidationException
     */
    private static function ids(string $name, mixed $operand): array
    {
        $ids = Operand::listOrFail($operand, $name, '$in', __('validation.filter.expected.record_ids'));

        foreach ($ids as $id) {
            if (! Str::isUlid($id)) {
                throw FilterErrors::at('$in', __('validation.filter.record_id', ['name' => "{$name} \$in", 'value' => $id]));
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @template TCase of EmailDirection|EmailCategory
     *
     * @param  class-string<TCase>  $enum
     * @return list<TCase>
     *
     * @throws ValidationException
     */
    private static function cases(string $name, mixed $operand, string $enum): array
    {
        $choices = implode(', ', array_map(static fn (BackedEnum $case): string => (string) $case->value, $enum::cases()));
        $values = Operand::listOrFail($operand, $name, '$in', __('validation.filter.expected.one_of', ['values' => $choices]));

        return array_values(array_unique(array_map(static fn (string $value): EmailDirection|EmailCategory => $enum::tryFrom($value)
            ?? throw FilterErrors::at('$in', __('validation.filter.enum_value', ['name' => "{$name} \$in", 'value' => $value, 'values' => $choices])), $values), SORT_REGULAR));
    }

    /** @param  list<EmailDirection>  $directions */
    private static function onlyDirection(array $directions): ?EmailDirection
    {
        return count($directions) === 1 ? $directions[0] : null;
    }

    /** @throws ValidationException */
    private static function text(string $name, string $operator, mixed $operand): string
    {
        $text = Operand::string($operand);

        if ($text === null || trim($text) === '') {
            throw FilterErrors::operand($name, $operator, __('validation.filter.expected.string'));
        }

        return trim($text);
    }

    /**
     * @param  array<string, mixed>  $operands
     * @return array<string, CarbonImmutable>
     *
     * @throws ValidationException
     */
    private static function sentAt(array $operands, string $viewerZone): array
    {
        $bounds = [];

        foreach (['$gte' => 'sentAfter', '$lt' => 'sentBefore'] as $operator => $property) {
            if (! array_key_exists($operator, $operands)) {
                continue;
            }

            $name = EmailFilter::SentAt->field();
            Operand::assertOffset($operator, "{$name} {$operator}", $operands[$operator], $viewerZone);

            $bounds[$property] = self::instant($operands[$operator], $viewerZone)
                ?? throw FilterErrors::operand($name, $operator, __('validation.filter.expected.date'));
        }

        return $bounds;
    }

    private static function instant(mixed $operand, string $viewerZone): ?CarbonImmutable
    {
        $date = Operand::date($operand);

        if (! $date instanceof CarbonImmutable || Operand::lacksOffset($operand)) {
            return null;
        }

        return Operand::isBareDate((string) $operand) ? Date::parse((string) $operand, $viewerZone) : $date;
    }

    /** @return array<string, mixed>|null */
    private function typed(string $word, string $value, string $viewerZone): ?array
    {
        $filter = EmailFilter::forTypedWord($word);
        $value = trim($value);

        if (! $filter instanceof EmailFilter || $value === '') {
            return null;
        }

        $word = mb_strtolower($word);

        return match ($filter) {
            EmailFilter::From => ['from' => $value],
            EmailFilter::To => ['to' => $value],
            EmailFilter::HasAttachment => mb_strtolower($value) === 'attachment' ? ['hasAttachment' => true] : null,
            EmailFilter::SentAt => ($instant = self::instant($value, $viewerZone)) instanceof CarbonImmutable
                ? [$word === 'after' ? 'sentAfter' : 'sentBefore' => $instant]
                : null,
            EmailFilter::Record => ['recordType' => EmailFilter::RECORD_WORDS[$word], 'recordIds' => [], 'recordName' => $value],
            EmailFilter::Mailbox, EmailFilter::Direction, EmailFilter::Category => null,
        };
    }
}
