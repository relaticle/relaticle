<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

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
     * @return list<array{label: string, prompt: string}>
     */
    public static function fromMeta(?string $meta): array
    {
        if ($meta === null) {
            return [];
        }

        $decoded = json_decode($meta, true);

        if (! is_array($decoded) || ! is_array($decoded['next_steps'] ?? null)) {
            return [];
        }

        $steps = [];

        foreach ($decoded['next_steps'] as $step) {
            if (! is_array($step)) {
                continue;
            }

            $label = $step['label'] ?? null;
            $prompt = $step['prompt'] ?? null;

            if (! is_string($label) || ! is_string($prompt) || $label === '' || $prompt === '') {
                continue;
            }

            $steps[] = ['label' => $label, 'prompt' => $prompt];
        }

        return $steps;
    }
}
