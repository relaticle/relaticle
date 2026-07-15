<?php

declare(strict_types=1);

namespace App\Enums;

use Relaticle\Chat\Enums\AiModel;

enum Plan: string
{
    case Free = 'free';
    case Pro = 'pro';
    case Enterprise = 'enterprise';

    public static function default(): self
    {
        return self::Free;
    }

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Free',
            self::Pro => 'Pro',
            self::Enterprise => 'Enterprise',
        };
    }

    public function credits(): int
    {
        return match ($this) {
            self::Free => 300,
            self::Pro => 2_000,
            self::Enterprise => 10_000,
        };
    }

    public function rateLimit(): int
    {
        return match ($this) {
            self::Free => 10,
            self::Pro => 30,
            self::Enterprise => 60,
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Free => 0,
            self::Pro => 1,
            self::Enterprise => 2,
        };
    }

    /** @return list<AiModel> */
    public function allowedModels(): array
    {
        return match ($this) {
            self::Free => [AiModel::Auto, AiModel::ClaudeSonnet, AiModel::Gemini3Flash, AiModel::Ollama],
            self::Pro, self::Enterprise => AiModel::cases(),
        };
    }

    public function allowsModel(AiModel $model): bool
    {
        return in_array($model, $this->allowedModels(), strict: true);
    }
}
