<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Support;

use League\CommonMark\Normalizer\SlugNormalizer;
use League\CommonMark\Normalizer\UniqueSlugNormalizer;

/**
 * Reproduces the exact heading-id algorithm spatie/laravel-markdown's
 * HeadingPermalinkExtension applies at render time -- League\CommonMark's
 * SlugNormalizer wrapped in a per-document UniqueSlugNormalizer -- so any
 * consumer that needs a page's real anchor ids without rendering full HTML
 * (the search index, the internal-link integrity guard) can't drift from
 * what actually lands in the DOM: duplicate heading text gets the same
 * `-1`, `-2`... suffix the renderer appends, and non-ASCII headings keep
 * their Unicode letters instead of being transliterated away.
 *
 * Only `##` headings are covered, matching DocsRepository's content model,
 * which only ever sections on h2 today -- even though config/markdown.php's
 * min_heading_level allows h2 through h6.
 */
final readonly class HeadingAnchors
{
    /** @return list<string> One entry per `##` heading, in document order. */
    public function __invoke(string $body): array
    {
        preg_match_all('/^##[ \t]+(.+)$/m', $body, $matches);

        $normalizer = new UniqueSlugNormalizer(new SlugNormalizer);

        return array_map(
            fn (string $heading): string => $normalizer->normalize(trim($heading)),
            $matches[1],
        );
    }
}
