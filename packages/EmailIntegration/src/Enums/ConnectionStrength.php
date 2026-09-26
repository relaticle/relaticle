<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ConnectionStrength: string implements HasColor, HasLabel
{
    case None = 'none';
    case VeryWeak = 'very_weak';
    case Weak = 'weak';
    case Good = 'good';
    case Strong = 'strong';
    case VeryStrong = 'very_strong';

    public function getLabel(): string
    {
        return __('filament/communication-intelligence.connection_strength.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::None => 'gray',
            self::VeryWeak => 'danger',
            self::Weak => 'warning',
            self::Good => 'info',
            self::Strong, self::VeryStrong => 'success',
        };
    }

    public static function fromScore(float $score): self
    {
        return match (true) {
            $score <= 0.0 => self::None,
            $score < 3.0 => self::VeryWeak,
            $score < 8.0 => self::Weak,
            $score < 20.0 => self::Good,
            $score < 40.0 => self::Strong,
            default => self::VeryStrong,
        };
    }
}
