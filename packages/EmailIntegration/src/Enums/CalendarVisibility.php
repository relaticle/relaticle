<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

enum CalendarVisibility: string
{
    case DEFAULT = 'default';
    case PUBLIC = 'public';
    case PRIVATE = 'private';
    case CONFIDENTIAL = 'confidential';

    public function isPrivate(): bool
    {
        return $this === self::PRIVATE || $this === self::CONFIDENTIAL;
    }
}
