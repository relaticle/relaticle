<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Marketing\Enums;

enum ConnectableAssistant: string
{
    case Claude = 'claude';
    case ChatGPT = 'chatgpt';

    public function label(): string
    {
        return match ($this) {
            self::Claude => 'Claude',
            self::ChatGPT => 'ChatGPT',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Claude => 'ri-claude-fill',
            self::ChatGPT => 'ri-openai-fill',
        };
    }

    public function sibling(): self
    {
        return match ($this) {
            self::Claude => self::ChatGPT,
            self::ChatGPT => self::Claude,
        };
    }

    public function routeName(): string
    {
        return "assistants.{$this->value}";
    }

    public function connectSummary(): string
    {
        return match ($this) {
            self::Claude => __('Connect Claude to the CRM with OAuth, what it can read and write, and the setup steps.'),
            self::ChatGPT => __('Install the Relaticle plugin in ChatGPT, what it can read and write, and the setup steps.'),
        };
    }
}
