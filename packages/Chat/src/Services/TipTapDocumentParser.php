<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class TipTapDocumentParser
{
    private const int MAX_DEPTH = 64;

    private const int MAX_NODES = 5000;

    /**
     * Walk a TipTap document JSON tree.
     *
     * @param  array<string, mixed>  $document
     * @return array{text: string, mentions: list<array{type: string, id: string, label: string}>}
     */
    public function parse(array $document, Team $team): array
    {
        $textParts = [];
        $mentions = [];
        $nodeCount = 0;

        $this->walkDocument($document, $textParts, $mentions, $nodeCount, 0);

        $mentions = $this->filterToTeam($mentions, $team);

        return [
            'text' => trim(implode('', $textParts)),
            'mentions' => $mentions,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $textParts
     * @param  list<array{type: string, id: string, label: string}>  $mentions
     */
    private function walkDocument(array $node, array &$textParts, array &$mentions, int &$nodeCount, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw ValidationException::withMessages(['document' => 'Message is too deep.']);
        }

        if (++$nodeCount > self::MAX_NODES) {
            throw ValidationException::withMessages(['document' => 'Message is too large.']);
        }

        $type = $node['type'] ?? null;

        if ($type === 'text') {
            $text = $node['text'] ?? null;
            if (is_string($text)) {
                $textParts[] = $text;
            }

            return;
        }

        if ($type === 'mention') {
            $attrs = $node['attrs'] ?? null;
            if (! is_array($attrs)) {
                return;
            }

            $mentionType = $attrs['type'] ?? null;
            $id = $attrs['id'] ?? null;
            $label = $attrs['label'] ?? '';

            if (! is_string($mentionType) || ! is_string($id)) {
                return;
            }

            $labelStr = is_string($label) ? $label : '';

            $mentions[] = [
                'type' => $mentionType,
                'id' => $id,
                'label' => $labelStr,
            ];

            if ($labelStr !== '') {
                $textParts[] = $labelStr;
            }

            return;
        }

        if ($type === 'hardBreak') {
            $textParts[] = "\n";

            return;
        }

        $content = $node['content'] ?? null;
        if (! is_array($content)) {
            return;
        }

        foreach ($content as $child) {
            if (is_array($child)) {
                $this->walkDocument($child, $textParts, $mentions, $nodeCount, $depth + 1);
            }
        }
    }

    /**
     * Build a TipTap document from plain text plus a list of stored mention rows.
     * Used at assistant stream-end to materialize the document for the assistant message.
     *
     * The text is split on each mention's label. Each label found in the text
     * (longest-first to avoid partial-prefix collisions) is replaced with a
     * mention node. Anything not matched stays as plain text nodes.
     *
     * @param  list<array{type: string, id: string, label: string}>  $mentionRows
     * @return array<string, mixed>
     */
    public function buildFromText(string $text, array $mentionRows, Team $team): array
    {
        if ($text === '') {
            return ['type' => 'doc', 'content' => []];
        }

        $authorized = $this->filterToTeam($mentionRows, $team);

        usort($authorized, static fn (array $a, array $b): int => mb_strlen($b['label']) <=> mb_strlen($a['label']));

        $nodes = $this->splitTextWithMentions($text, $authorized);

        return [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => $nodes,
            ]],
        ];
    }

    /**
     * @param  list<array{type: string, id: string, label: string}>  $mentions
     * @return list<array<string, mixed>>
     */
    private function splitTextWithMentions(string $text, array $mentions): array
    {
        if ($mentions === [] || $text === '') {
            return $text === '' ? [] : [['type' => 'text', 'text' => $text]];
        }

        $segments = [['type' => 'text', 'text' => $text]];

        foreach ($mentions as $mention) {
            $needle = $mention['label'];
            if ($needle === '') {
                continue;
            }

            $pattern = '/(?<![\p{L}\p{N}_])'.preg_quote($needle, '/').'(?![\p{L}\p{N}_])/u';

            $next = [];
            foreach ($segments as $segment) {
                if ($segment['type'] !== 'text') {
                    $next[] = $segment;

                    continue;
                }

                $parts = preg_split($pattern, $segment['text']);
                if ($parts === false) {
                    $next[] = $segment;

                    continue;
                }

                $lastIndex = count($parts) - 1;
                foreach ($parts as $i => $part) {
                    if ($part !== '') {
                        $next[] = ['type' => 'text', 'text' => $part];
                    }
                    if ($i < $lastIndex) {
                        $next[] = [
                            'type' => 'mention',
                            'attrs' => [
                                'type' => $mention['type'],
                                'id' => $mention['id'],
                                'label' => $mention['label'],
                            ],
                        ];
                    }
                }
            }
            $segments = $next;
        }

        return $segments;
    }

    /**
     * @param  list<array{type: string, id: string, label?: string}>  $mentions
     * @return list<array{type: string, id: string, label: string}>
     */
    private function filterToTeam(array $mentions, Team $team): array
    {
        if ($mentions === []) {
            return [];
        }

        $byType = [];
        foreach ($mentions as $m) {
            $byType[$m['type']][] = $m['id'];
        }

        $authorized = [];
        foreach ($byType as $type => $ids) {
            $modelClass = $this->modelForType($type);
            if ($modelClass === null) {
                continue;
            }

            $found = $modelClass::query()
                ->whereBelongsTo($team)
                ->whereIn('id', array_unique($ids))
                ->pluck('id')
                ->all();

            $authorized[$type] = array_flip($found);
        }

        $result = [];
        foreach ($mentions as $m) {
            if (isset($authorized[$m['type']][$m['id']])) {
                $result[] = [
                    'type' => $m['type'],
                    'id' => $m['id'],
                    'label' => $m['label'] ?? '',
                ];
            }
        }

        return $result;
    }

    /**
     * @return class-string<Model>|null
     */
    private function modelForType(string $type): ?string
    {
        return match ($type) {
            'company' => Company::class,
            'people' => People::class,
            'opportunity' => Opportunity::class,
            'task' => Task::class,
            'note' => Note::class,
            default => null,
        };
    }
}
