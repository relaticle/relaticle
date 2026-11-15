<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

final readonly class TurnNotes
{
    public static function timeout(int|string $seconds): string
    {
        return __('This reply hit the :seconds-second limit before it finished. Ask for a shorter answer, or for it in parts.', [
            'seconds' => $seconds,
        ]);
    }

    public static function timeoutWithProposals(int|string $seconds): string
    {
        return __('This reply hit the :seconds-second limit before it finished. The steps it had already proposed were kept. Approve or discard them, then ask for the rest.', [
            'seconds' => $seconds,
        ]);
    }

    /** @return array{length: string, content_filter: string, noted: string} */
    public static function blankReply(): array
    {
        return [
            'length' => __('This reply ran out of room before it said anything. Ask again, or ask for a shorter answer.'),
            'content_filter' => __('The model declined to answer this. Try rephrasing your request.'),
            'noted' => __('Noted. Go on, or ask me a question.'),
        ];
    }
}
