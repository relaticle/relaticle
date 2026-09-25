<?php

declare(strict_types=1);

namespace App\Enums;

enum InlineCommit
{
    case OnChange;
    case OnEnterOrBlur;
    case InModal;

    public static function forType(CustomFieldType $type): ?self
    {
        if (! $type->isInlineEditable()) {
            return null;
        }

        if ($type->opensInModal()) {
            return self::InModal;
        }

        if ($type->savesOnChange()) {
            return self::OnChange;
        }

        return self::OnEnterOrBlur;
    }
}
