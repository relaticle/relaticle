<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Support;

final readonly class PageSections
{
    private const int MAX_SECTION_LEVEL = 3;

    /** @return list<array{section: string, anchor: string, markdown: string}> */
    public function __invoke(string $body): array
    {
        $anchors = array_values(array_filter(
            (new HeadingAnchors)->all($body),
            fn (array $heading): bool => $heading['level'] <= self::MAX_SECTION_LEVEL,
        ));

        $chunks = preg_split('/^(#{2,3})[ \t]+(.+)$/m', $body, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$body];

        $sections = [['section' => '', 'anchor' => '', 'markdown' => $chunks[0]]];
        $parent = '';

        for ($i = 1, $heading = 0; $i < count($chunks); $i += 3, $heading++) {
            $text = trim($chunks[$i + 1]);

            if (strlen($chunks[$i]) === 2) {
                $parent = $text;
            }

            $sections[] = [
                'section' => strlen($chunks[$i]) === 2 || $parent === '' ? $text : "{$parent} / {$text}",
                'anchor' => $anchors[$heading]['anchor'] ?? '',
                'markdown' => $chunks[$i + 2] ?? '',
            ];
        }

        return $sections;
    }
}
