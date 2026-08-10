<?php

declare(strict_types=1);

namespace App\Enums;

enum SupportFormType: string
{
    case Contact = 'contact';
    case Bug = 'bug';
    case Feature = 'feature';

    public function label(): string
    {
        return match ($this) {
            self::Contact => __('support.menu.contact'),
            self::Bug => __('support.menu.bug'),
            self::Feature => __('support.menu.feature'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Contact => 'heroicon-o-lifebuoy',
            self::Bug => 'heroicon-o-bug-ant',
            self::Feature => 'heroicon-o-light-bulb',
        };
    }
}
