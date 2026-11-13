<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

final class MarkdownText
{
    public static function escape(string $text): string
    {
        // Blade encodes & < > " ' before CommonMark runs; escaping them too leaves entities as visible text.
        return (string) preg_replace('/(?![&<>"\'])[[:punct:]]/', '\\\\$0', $text);
    }
}
