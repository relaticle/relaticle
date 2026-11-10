<?php

declare(strict_types=1);

namespace App\Enums;

enum FilterCondition: string
{
    case Contains = 'contains';
    case DoesNotContain = 'does_not_contain';
    case StartsWith = 'starts_with';
    case EndsWith = 'ends_with';
    case Is = 'is';
    case IsNot = 'is_not';
    case IsEmpty = 'is_empty';
    case IsNotEmpty = 'is_not_empty';
    case GreaterThan = 'greater_than';
    case GreaterOrEqual = 'greater_or_equal';
    case LessThan = 'less_than';
    case LessOrEqual = 'less_or_equal';
    case Before = 'before';
    case AtOrBefore = 'at_or_before';
    case After = 'after';
    case AtOrAfter = 'at_or_after';
    case InThePast = 'in_the_past';
    case InTheNext = 'in_the_next';
    case This = 'this';
    case IsAnyOf = 'is_any_of';
    case IsNoneOf = 'is_none_of';
    case HasAnyOf = 'has_any_of';
    case HasNoneOf = 'has_none_of';
    case IsChecked = 'is_checked';
    case IsNotChecked = 'is_not_checked';
    case NoActivityFor = 'no_activity_for';

    public function label(): string
    {
        return __('filament/panel.tables.filters.conditions.'.$this->value);
    }

    public function takesValue(): bool
    {
        return match ($this) {
            self::IsEmpty, self::IsNotEmpty, self::IsChecked, self::IsNotChecked => false,
            self::Contains, self::DoesNotContain, self::StartsWith, self::EndsWith,
            self::Is, self::IsNot,
            self::GreaterThan, self::GreaterOrEqual, self::LessThan, self::LessOrEqual,
            self::Before, self::AtOrBefore, self::After, self::AtOrAfter,
            self::InThePast, self::InTheNext, self::This,
            self::IsAnyOf, self::IsNoneOf, self::HasAnyOf, self::HasNoneOf,
            self::NoActivityFor => true,
        };
    }

    public function isNegated(FilterFieldKind $kind): bool
    {
        return match ($this) {
            self::DoesNotContain, self::IsNotChecked => true,
            self::IsNot => $kind !== FilterFieldKind::MultiText,
            self::Contains, self::StartsWith, self::EndsWith, self::Is,
            self::IsEmpty, self::IsNotEmpty,
            self::GreaterThan, self::GreaterOrEqual, self::LessThan, self::LessOrEqual,
            self::Before, self::AtOrBefore, self::After, self::AtOrAfter,
            self::InThePast, self::InTheNext, self::This,
            self::IsAnyOf, self::IsNoneOf, self::HasAnyOf, self::HasNoneOf,
            self::IsChecked, self::NoActivityFor => false,
        };
    }

    public function fixedOperand(): ?bool
    {
        return match ($this) {
            self::IsEmpty, self::IsChecked, self::IsNotChecked => true,
            self::IsNotEmpty => false,
            self::Contains, self::DoesNotContain, self::StartsWith, self::EndsWith,
            self::Is, self::IsNot,
            self::GreaterThan, self::GreaterOrEqual, self::LessThan, self::LessOrEqual,
            self::Before, self::AtOrBefore, self::After, self::AtOrAfter,
            self::InThePast, self::InTheNext, self::This,
            self::IsAnyOf, self::IsNoneOf, self::HasAnyOf, self::HasNoneOf,
            self::NoActivityFor => null,
        };
    }

    public function takesList(FilterFieldKind $kind): bool
    {
        return match ($this) {
            self::IsAnyOf, self::IsNoneOf, self::HasAnyOf, self::HasNoneOf => true,
            self::Is, self::IsNot => $kind === FilterFieldKind::MultiText,
            self::Contains, self::DoesNotContain, self::StartsWith, self::EndsWith,
            self::IsEmpty, self::IsNotEmpty,
            self::GreaterThan, self::GreaterOrEqual, self::LessThan, self::LessOrEqual,
            self::Before, self::AtOrBefore, self::After, self::AtOrAfter,
            self::InThePast, self::InTheNext, self::This,
            self::IsChecked, self::IsNotChecked, self::NoActivityFor => false,
        };
    }

    public function isPeriod(): bool
    {
        return match ($this) {
            self::InThePast, self::InTheNext, self::This => true,
            self::Contains, self::DoesNotContain, self::StartsWith, self::EndsWith,
            self::Is, self::IsNot, self::IsEmpty, self::IsNotEmpty,
            self::GreaterThan, self::GreaterOrEqual, self::LessThan, self::LessOrEqual,
            self::Before, self::AtOrBefore, self::After, self::AtOrAfter,
            self::IsAnyOf, self::IsNoneOf, self::HasAnyOf, self::HasNoneOf,
            self::IsChecked, self::IsNotChecked, self::NoActivityFor => false,
        };
    }

    /** @return list<string> */
    public function operators(FilterFieldKind $kind): array
    {
        return match ($this) {
            self::Contains, self::DoesNotContain => ['$contains'],
            self::StartsWith => ['$starts_with'],
            self::EndsWith => ['$ends_with'],
            self::Is => $kind === FilterFieldKind::MultiText ? ['$has_any'] : ['$eq'],
            self::IsNot => $kind === FilterFieldKind::MultiText ? ['$has_none'] : ['$eq'],
            self::IsEmpty, self::IsNotEmpty => ['$is_empty'],
            self::GreaterThan, self::After => ['$gt'],
            self::GreaterOrEqual, self::AtOrAfter, self::NoActivityFor => ['$gte'],
            self::LessThan, self::Before => ['$lt'],
            self::LessOrEqual, self::AtOrBefore => ['$lte'],
            self::InThePast, self::InTheNext, self::This => ['$gte', '$lte'],
            self::IsAnyOf => ['$in'],
            self::IsNoneOf => ['$not_in'],
            self::HasAnyOf => ['$has_any'],
            self::HasNoneOf => ['$has_none'],
            self::IsChecked, self::IsNotChecked => ['$eq'],
        };
    }

    public function valueShape(FilterFieldKind $kind): string
    {
        return match (true) {
            ! $this->takesValue() => 'none',
            $this === self::This => 'unit',
            $this->isPeriod() => 'period',
            $kind->holdsList() => 'list',
            $kind === FilterFieldKind::Number, $kind === FilterFieldKind::Inactivity => 'number',
            default => 'text',
        };
    }
}
