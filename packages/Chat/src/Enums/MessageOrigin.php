<?php

declare(strict_types=1);

namespace Relaticle\Chat\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Facades\Context;

enum MessageOrigin: string implements HasColor, HasLabel
{
    case Typed = 'typed';

    case Greeting = 'greeting';

    case Resume = 'resume';

    case DataArrived = 'data_arrived';

    public const string CONTEXT_KEY = 'chat.message_origin';

    public static function current(): self
    {
        $value = Context::getHidden(self::CONTEXT_KEY);

        return is_string($value) ? self::from($value) : self::Typed;
    }

    public function isTyped(): bool
    {
        return $this === self::Typed;
    }

    public function opener(): ?string
    {
        return match ($this) {
            self::Typed => null,
            self::Greeting => 'The user opened their setup conversation.',
            self::Resume => null,
            self::DataArrived => 'Records just arrived in the workspace.',
        };
    }

    public function directive(): ?string
    {
        return match ($this) {
            self::Typed => null,
            self::Greeting => 'The user finished signing up a moment ago and just opened this conversation. Nobody has typed anything: you speak first, and they are watching this message appear. Greet them by first name. Say in one line what their workspace is ready for, naming the first and last stage from the stages line when there is one. Then give the next step for their data. When an arrived line is present, say how many people and companies came in and from where, then make one offer: to list the companies so they can pick who to work on first when an arrived line counts more than 0 companies, otherwise to add the companies they work with. With no arrived line and the mailbox line connected, say their mailbox is connected and the people and companies they email appear here as it syncs, then ask which people and deals they are working on right now, and say a pasted list works too. With no arrived line and the mailbox line not_connected, ask how they want to bring their clients in and point them to the choices below, and say a pasted list works too. Three short paragraphs at most, no lists, no headings, and call no tools in this turn.',
            self::DataArrived => 'Records just arrived in this workspace and the user has come back to this conversation, so you speak first. The arrived line says how many people and companies came in and from where: say so in one sentence, using only those numbers, or say their records arrived when there is no arrived line. Then make one offer: to list the companies that arrived so they can pick who to work on first when an arrived line counts more than 0 companies, otherwise to add the companies they work with. Two short paragraphs at most, no lists, no headings, and call no tools in this turn.',
            self::Resume => 'The user just decided the proposals from your last turn. The latest user message is the system\'s record of each decision, and <resolved_actions> marks the same entries JUST DECIDED. Reply as the Resuming section says.',
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Typed => __('Typed'),
            self::Greeting => __('Greeting'),
            self::Resume => __('Resume'),
            self::DataArrived => __('Data arrived'),
        };
    }

    public function getColor(): string
    {
        return $this->isTyped() ? 'gray' : 'warning';
    }
}
