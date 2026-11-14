<?php

declare(strict_types=1);

namespace App\Enums;

enum FilterFieldKind: string
{
    case Text = 'text';
    case Number = 'number';
    case Date = 'date';
    case Choice = 'choice';
    case MultiChoice = 'multi_choice';
    case Checkbox = 'checkbox';
    case MultiText = 'multi_text';
    case Member = 'member';
    case Relation = 'relation';
    case Record = 'record';
    case Inactivity = 'inactivity';

    /** @return list<FilterCondition> */
    public function conditions(): array
    {
        $emptiness = [FilterCondition::IsEmpty, FilterCondition::IsNotEmpty];
        $textual = [
            FilterCondition::Contains, FilterCondition::DoesNotContain, FilterCondition::StartsWith, FilterCondition::EndsWith,
            FilterCondition::Is, FilterCondition::IsNot,
        ];

        return match ($this) {
            self::Text, self::MultiText => [...$textual, ...$emptiness],
            self::Number => [
                FilterCondition::Is, FilterCondition::IsNot,
                FilterCondition::GreaterThan, FilterCondition::GreaterOrEqual, FilterCondition::LessThan, FilterCondition::LessOrEqual,
                ...$emptiness,
            ],
            self::Date => [
                FilterCondition::Is,
                FilterCondition::Before, FilterCondition::AtOrBefore, FilterCondition::After, FilterCondition::AtOrAfter,
                FilterCondition::InThePast, FilterCondition::InTheNext, FilterCondition::This,
                ...$emptiness,
            ],
            self::Choice, self::Member, self::Relation => [FilterCondition::IsAnyOf, FilterCondition::IsNoneOf, ...$emptiness],
            self::MultiChoice, self::Record => [FilterCondition::HasAnyOf, FilterCondition::HasNoneOf, ...$emptiness],
            self::Checkbox => [FilterCondition::IsChecked, FilterCondition::IsNotChecked],
            self::Inactivity => [FilterCondition::NoActivityFor],
        };
    }

    public function legacyCondition(): ?FilterCondition
    {
        return match ($this) {
            self::Choice, self::Member => FilterCondition::IsAnyOf,
            self::MultiChoice, self::Record => FilterCondition::HasAnyOf,
            self::Text, self::Number, self::Date, self::Checkbox, self::MultiText, self::Relation, self::Inactivity => null,
        };
    }

    public function holdsRecordIds(): bool
    {
        return match ($this) {
            self::Member, self::Relation, self::Record => true,
            self::Text, self::Number, self::Date, self::Choice, self::MultiChoice, self::Checkbox, self::MultiText, self::Inactivity => false,
        };
    }

    public function holdsList(): bool
    {
        return match ($this) {
            self::Choice, self::MultiChoice, self::Member, self::Relation, self::Record => true,
            self::Text, self::Number, self::Date, self::Checkbox, self::MultiText, self::Inactivity => false,
        };
    }
}
