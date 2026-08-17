<?php

declare(strict_types=1);

namespace App\Support\Markdown;

use Spatie\MarkdownResponse\Postprocessors\Postprocessor;

/**
 * league/html-to-markdown's TextConverter escapes '&', '<', and '>' via
 * htmlspecialchars() before writing markdown text back into a DOM text node.
 * DOMDocument::saveHTML() then escapes that literal text a second time on
 * serialization (e.g. "&" becomes "&amp;" becomes "&amp;amp;" on the wire),
 * and the library's own HtmlConverter::sanitize() only runs html_entity_decode()
 * once, so one level of escaping survives into the final markdown ("Import
 * &amp; Export" instead of "Import & Export"). This postprocessor runs the
 * second decode pass the library never does.
 */
final readonly class DecodeHtmlEntitiesPostprocessor implements Postprocessor
{
    public function __invoke(string $markdown): string
    {
        return html_entity_decode($markdown, ENT_QUOTES, 'UTF-8');
    }
}
