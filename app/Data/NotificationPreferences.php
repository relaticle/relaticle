<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\Notifications\NotificationChannel;
use App\Enums\Notifications\NotificationType;

final readonly class NotificationPreferences
{
    /** @param array<string, array<string, bool>> $overrides */
    public function __construct(public array $overrides = []) {}

    public function wants(NotificationType $type, NotificationChannel $channel): bool
    {
        return $this->overrides[$type->value][$channel->value] ?? $type->defaultEnabled($channel);
    }

    public function with(NotificationType $type, NotificationChannel $channel, bool $enabled): self
    {
        $overrides = $this->overrides;
        $overrides[$type->value][$channel->value] = $enabled;

        return new self($overrides);
    }
}
