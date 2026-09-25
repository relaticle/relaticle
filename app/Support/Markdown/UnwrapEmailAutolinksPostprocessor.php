<?php

declare(strict_types=1);

namespace App\Support\Markdown;

use Spatie\MarkdownResponse\Postprocessors\Postprocessor;

/**
 * league/html-to-markdown renders a mailto link whose text equals its address
 * as the markdown autolink form "<user@example.com>". That looks like an HTML
 * tag to RemoveHtmlTagsPostprocessor's strip_tags(), which deletes it, so every
 * email address vanishes from the markdown variant of a page. Unwrapping the
 * autolink to a bare address before the tag stripper runs keeps it on the wire.
 */
final readonly class UnwrapEmailAutolinksPostprocessor implements Postprocessor
{
    public function __invoke(string $markdown): string
    {
        return preg_replace('/<([^\s<>@]+@[^\s<>@]+\.[^\s<>@]+)>/', '$1', $markdown) ?? $markdown;
    }
}
