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
            self::Greeting => 'The user finished signing up a moment ago and just opened this conversation. Nobody has typed anything: you speak first, and they are watching this message appear. Greet them by first name. Say in one line what their workspace is ready for, naming the first and last stage from the stages line when there is one. Then give the next step for their data_source line. When an arrived line is present, say how many people and companies came in and from where, and offer to list the companies so they can pick who to work on first. With no arrived line: spreadsheet or other_crm means their file is the next step, so offer to bring it in as a CSV; memory or inbox means ask which people and deals they are working on right now, and say a pasted list works too; none means ask them to paste a list of people or companies in any columns and any order, attach a CSV, or describe a few people they are working with right now; assistant means connecting their own assistant is the next step, so offer to show them how. Three short paragraphs at most, no lists, no headings, and call no tools in this turn.',
            self::DataArrived => 'Records just arrived in this workspace and the user has come back to this conversation, so you speak first. The arrived line says how many people and companies came in and from where: say so in one sentence, using only those numbers, or say their records arrived when there is no arrived line. Then offer to list the companies that arrived so they can pick who to work on first. Two short paragraphs at most, no lists, no headings, and call no tools in this turn.',
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
