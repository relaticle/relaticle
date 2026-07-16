<?php

declare(strict_types=1);

namespace App\Livewire\App\Notifications;

use App\Actions\User\UpdateNotificationPreferences;
use App\Enums\Notifications\NotificationChannel;
use App\Enums\Notifications\NotificationType;
use App\Livewire\BaseLivewireComponent;
use Illuminate\Contracts\View\View;

final class ManageNotificationPreferences extends BaseLivewireComponent
{
    /** @var array<string, array<string, bool>> */
    public array $cells = [];

    public bool $digestEnabled = true;

    public function mount(): void
    {
        $user = $this->authUser();

        foreach (NotificationType::collaboration() as $type) {
            foreach ($type->channels() as $channel) {
                $this->cells[$type->value][$channel->value] = $user->wantsNotification($type, $channel);
            }
        }

        $this->digestEnabled = $user->wantsNotification(NotificationType::TaskDigest, NotificationChannel::Email);
    }

    public function updatedCells(bool $value, string $key): void
    {
        $parts = explode('.', $key);

        if (count($parts) !== 2) {
            return;
        }

        [$type, $channel] = $parts;

        $notificationType = NotificationType::tryFrom($type);
        $notificationChannel = NotificationChannel::tryFrom($channel);

        if (! $notificationType instanceof NotificationType || ! $notificationChannel instanceof NotificationChannel) {
            return;
        }

        $this->persist($notificationType, $notificationChannel, $value);
    }

    public function updatedDigestEnabled(bool $value): void
    {
        $this->persist(NotificationType::TaskDigest, NotificationChannel::Email, $value);
    }

    public function render(): View
    {
        return view('livewire.app.notifications.manage-notification-preferences', [
            'types' => NotificationType::collaboration(),
            'channels' => NotificationChannel::cases(),
        ]);
    }

    private function persist(NotificationType $type, NotificationChannel $channel, bool $enabled): void
    {
        resolve(UpdateNotificationPreferences::class)->execute($this->authUser(), $type, $channel, $enabled);
    }
}
