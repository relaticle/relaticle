<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use Relaticle\Chat\Data\SetupOption;

/**
 * Reads the next steps SuggestNextSteps wrote onto an assistant message's
 * `meta`, in the shape the transcript strip renders.
 *
 * Every row predating the feature, every row whose turn ended on a proposal,
 * and every row the suggester declined to annotate has no `next_steps` key at
 * all, so an empty list is the normal answer rather than an error.
 */
final readonly class NextSteps
{
    /**
     * @param  array<mixed>  $meta
     * @return list<array{
     *     id: ?string,
     *     kind: string,
     *     label: string,
     *     prompt: ?string,
     *     url: ?string,
     *     icon: ?string
     * }>
     */
    public static function fromMeta(array $meta): array
    {
        if (! is_array($meta['next_steps'] ?? null)) {
            return [];
        }

        $steps = [];

        foreach ($meta['next_steps'] as $step) {
            if (! is_array($step)) {
                continue;
            }

            $normalized = self::normalize($step);

            if ($normalized !== null) {
                $steps[] = $normalized;
            }
        }

        return $steps;
    }

    /**
     * @param  array<mixed>  $step
     * @return array{id: ?string, kind: string, label: string, prompt: ?string, url: ?string, icon: ?string}|null
     */
    private static function normalize(array $step): ?array
    {
        $label = self::text($step['label'] ?? null);
        $prompt = self::text($step['prompt'] ?? null);
        $url = self::text($step['url'] ?? null);
        $isLink = ($step['kind'] ?? null) === SetupOption::KIND_LINK;

        if ($label === null || ($isLink ? $url : $prompt) === null) {
            return null;
        }

        return [
            'id' => self::text($step['id'] ?? null),
            'kind' => $isLink ? SetupOption::KIND_LINK : SetupOption::KIND_PROMPT,
            'label' => $label,
            'prompt' => $prompt,
            'url' => $url,
            'icon' => self::text($step['icon'] ?? null),
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
