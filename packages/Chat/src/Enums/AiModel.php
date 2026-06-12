<?php

declare(strict_types=1);

namespace Relaticle\Chat\Enums;

enum AiModel: string
{
    case Auto = 'auto';
    case ClaudeSonnet = 'claude-sonnet';
    case ClaudeOpus = 'claude-opus';
    case Gpt5_5 = 'gpt-5-5';
    case Gpt5_4 = 'gpt-5-4';
    case Gemini3Flash = 'gemini-3-flash';
    case Gemini31Pro = 'gemini-3-1-pro';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Auto',
            self::ClaudeSonnet => 'Sonnet 4.6',
            self::ClaudeOpus => 'Opus 4.7',
            self::Gpt5_5 => 'GPT 5.5',
            self::Gpt5_4 => 'GPT 5.4',
            self::Gemini3Flash => 'Gemini 3 Flash',
            self::Gemini31Pro => 'Gemini 3.1 Pro',
        };
    }

    public function provider(): ?string
    {
        return match ($this) {
            self::Auto => null,
            self::ClaudeSonnet, self::ClaudeOpus => 'anthropic',
            self::Gpt5_5, self::Gpt5_4 => 'openai',
            self::Gemini3Flash, self::Gemini31Pro => 'gemini',
        };
    }

    public function modelId(): ?string
    {
        return match ($this) {
            self::Auto => null,
            self::ClaudeSonnet => 'claude-sonnet-4-6',
            self::ClaudeOpus => 'claude-opus-4-7',
            self::Gpt5_5 => 'gpt-5.5',
            self::Gpt5_4 => 'gpt-5.4',
            self::Gemini3Flash => 'gemini-3-flash',
            self::Gemini31Pro => 'gemini-3.1-pro',
        };
    }

    public function creditMultiplier(): float
    {
        return match ($this) {
            self::Auto, self::ClaudeSonnet, self::Gemini3Flash => 1.0,
            self::ClaudeOpus => 3.0,
            self::Gpt5_5, self::Gpt5_4 => 1.5,
            self::Gemini31Pro => 1.5,
        };
    }

    public static function multiplierForModelId(string $modelId): float
    {
        foreach (self::cases() as $case) {
            if ($case->modelId() === $modelId) {
                return $case->creditMultiplier();
            }
        }

        return 1.0;
    }
}
