<?php

declare(strict_types=1);

namespace App\Enums\Notifications;

enum NotificationType: string
{
    case TaskAssigned = 'task_assigned';
    case TaskDigest = 'task_digest';

    public function group(): NotificationGroup
    {
        return match ($this) {
            self::TaskAssigned => NotificationGroup::Collaboration,
            self::TaskDigest => NotificationGroup::Digest,
        };
    }

    public function label(): string
    {
        return __("notifications.types.{$this->value}.label");
    }

    public function description(): string
    {
        return __("notifications.types.{$this->value}.description");
    }

    /** @return array<int, NotificationChannel> */
    public function channels(): array
    {
        return match ($this) {
            self::TaskAssigned => [NotificationChannel::InApp, NotificationChannel::Email],
            self::TaskDigest => [NotificationChannel::Email],
        };
    }

    public function defaultEnabled(NotificationChannel $channel): bool
    {
        return match ($this) {
            self::TaskAssigned => $channel === NotificationChannel::InApp,
            self::TaskDigest => true,
        };
    }

    /** @return array<int, self> */
    public static function collaboration(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $type): bool => $type->group() === NotificationGroup::Collaboration,
        ));
    }

    /** @return array<int, self> */
    public static function digests(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $type): bool => $type->group() === NotificationGroup::Digest,
        ));
    }
}
