<?php

declare(strict_types=1);

namespace Tests\Helpers;

final class ChatDocument
{
    /**
     * @param  list<array{type: string, id: string, label: string}>  $mentions
     * @return array<string, mixed>
     */
    public static function fromText(string $text, array $mentions = []): array
    {
        $content = [['type' => 'text', 'text' => $text]];
        foreach ($mentions as $mention) {
            $content[] = [
                'type' => 'mention',
                'attrs' => [
                    'type' => $mention['type'],
                    'id' => $mention['id'],
                    'label' => $mention['label'],
                ],
            ];
        }

        return [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => $content]],
        ];
    }

    /**
     * Empty TipTap document — useful as the `document` JSON for tests
     * that directly INSERT into agent_conversation_messages and don't
     * care about the document content.
     */
    public static function emptyJson(): string
    {
        return json_encode(['type' => 'doc', 'content' => []], JSON_THROW_ON_ERROR);
    }
}
