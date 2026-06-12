<?php

declare(strict_types=1);

namespace App\Filament\Notifications;

use Filament\Notifications\Collection;
use Filament\Notifications\Notification;

/**
 * Stale or multi-tab Livewire clients occasionally send back a notifications
 * payload containing non-array junk (e.g. a bare int), which the parent
 * collection's typed closure turns into a TypeError on every subsequent
 * request from that tab (Sentry #120218486). Drop anything that is not a
 * notification-shaped array before transforming.
 */
final class NotificationCollection extends Collection
{
    public static function fromLivewire($value): static
    {
        $items = array_filter($value, is_array(...));

        return resolve(self::class, ['items' => $items])->transform(
            fn (array $notification): Notification => Notification::fromArray($notification),
        );
    }
}
