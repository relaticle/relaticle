<?php

declare(strict_types=1);

namespace Relaticle\Chat\Enums;

use App\Models\User;
use Filament\Support\Contracts\HasLabel;

enum WebSearchPreference: string implements HasLabel
{
    case Ask = 'ask';
    case Allow = 'allow';
    case Deny = 'deny';

    public static function of(User $user): self
    {
        $stored = $user->ai_preferences['web_search'] ?? null;

        return is_string($stored) ? (self::tryFrom($stored) ?? self::Ask) : self::Ask;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Ask => __('Always ask'),
            self::Allow => __('Always allow'),
            self::Deny => __("Don't allow"),
        };
    }
}
