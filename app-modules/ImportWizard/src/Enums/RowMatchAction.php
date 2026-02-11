<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Enums;

enum RowMatchAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Skip = 'skip';

    public function icon(): string
    {
        return match ($this) {
            self::Create => 'heroicon-m-plus',
            self::Update => 'heroicon-m-arrow-path',
            self::Skip => 'heroicon-m-minus',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Create => 'text-success-500',
            self::Update => 'text-primary-500',
            self::Skip => 'text-gray-400',
        };
    }
}
