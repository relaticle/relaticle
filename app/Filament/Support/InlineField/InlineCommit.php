<?php

declare(strict_types=1);

namespace App\Filament\Support\InlineField;

use App\Enums\CustomFieldType;

enum InlineCommit
{
    case OnChange;
    case OnEnterOrBlur;
    case OnConfirm;

    public static function forType(CustomFieldType $type): ?self
    {
        if (! $type->isInlineEditable()) {
            return null;
        }

        if ($type->requiresExplicitConfirm()) {
            return self::OnConfirm;
        }

        if ($type->savesOnChange()) {
            return self::OnChange;
        }

        return self::OnEnterOrBlur;
    }
}
