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
    case Ollama = 'ollama';

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
            self::Ollama => self::ollamaModelTag() ?? 'Ollama',
        };
    }

    public function provider(): ?string
    {
        return match ($this) {
            self::Auto => null,
            self::ClaudeSonnet, self::ClaudeOpus => 'anthropic',
            self::Gpt5_5, self::Gpt5_4 => 'openai',
            self::Gemini3Flash, self::Gemini31Pro => 'gemini',
            self::Ollama => 'ollama',
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
            self::Ollama => self::ollamaModelTag(),
        };
    }

    /**
     * Whether this model can serve requests on this installation. Cloud
     * models need their provider's API key; Ollama needs an explicitly
     * configured model tag. Gemini stays excluded until laravel/ai's Gemini
     * driver supports tool_config (see the note on CrmAssistant).
     */
    public function available(): bool
    {
        return match ($this) {
            self::Auto => true,
            self::ClaudeSonnet, self::ClaudeOpus => filled(config('ai.providers.anthropic.key')),
            self::Gpt5_5, self::Gpt5_4 => filled(config('ai.providers.openai.key')),
            self::Gemini3Flash, self::Gemini31Pro => false,
            self::Ollama => self::ollamaModelTag() !== null,
        };
    }

    public function creditMultiplier(): float
    {
        return match ($this) {
            self::Auto, self::ClaudeSonnet, self::Gemini3Flash, self::Ollama => 1.0,
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

    /**
     * The options rendered by the chat model pickers.
     *
     * @return list<array{value: string, label: string, provider: string|null}>
     */
    public static function pickerOptions(): array
    {
        return array_values(collect(self::cases())
            ->filter(fn (self $model): bool => $model->available())
            ->map(fn (self $model): array => [
                'value' => $model->value,
                'label' => $model->label(),
                'provider' => $model->provider(),
            ])
            ->all());
    }

    private static function ollamaModelTag(): ?string
    {
        $tag = config('ai.providers.ollama.models.text.default');

        return is_string($tag) && $tag !== '' ? $tag : null;
    }
}
