<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

enum ShortcutScope: string
{
    case List = 'list';
    case Reader = 'reader';
    case Composer = 'composer';
    case Global = 'global';

    public function label(): string
    {
        return match ($this) {
            self::List => __('filament/pages/email-inbox.shortcuts.scopes.list'),
            self::Reader => __('filament/pages/email-inbox.shortcuts.scopes.reader'),
            self::Composer => __('filament/pages/email-inbox.shortcuts.scopes.composer'),
            self::Global => __('filament/pages/email-inbox.shortcuts.scopes.global'),
        };
    }
}
