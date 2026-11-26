<?php

declare(strict_types=1);

namespace App\Support;

final readonly class RichTextSearch
{
    public const string ELEMENT_CONDITION = "(cf.type = 'rich-editor' and replace(replace(regexp_replace(cfv.text_value, '<[^>]*>', ' ', 'g'), '&nbsp;', ' '), '&amp;', '&') ilike ?)";
}
