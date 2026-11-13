<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Enums\Notifications\NotificationReason;
use App\Models\Notification;
use App\Models\Task;
use App\Models\Workspace;
use App\Notifications\InboxNotification;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Database\Eloquent\Model;

final class GroupedInboxNotification extends InboxNotification
{
    public function __construct(
        private readonly Task $task,
        private readonly string $groupKey,
        private readonly string $label,
        private readonly bool $collapsesReadRows = false,
    ) {}

    public function workspace(): Workspace
    {
        return $this->task->workspace;
    }

    public function subject(): Model
    {
        return $this->task;
    }

    public function reason(): NotificationReason
    {
        return NotificationReason::System;
    }

    public function groupKey(): string
    {
        return $this->groupKey;
    }

    public function collapsesReadRows(): bool
    {
        return $this->collapsesReadRows;
    }

    /** @return array<string, mixed> */
    public function facts(): array
    {
        return ['labels' => [$this->label]];
    }

    /**
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    public function mergeFacts(array $existing): array
    {
        return ['labels' => [...$existing['labels'], $this->label]];
    }

    public static function present(Notification $row): FilamentNotification
    {
        return FilamentNotification::make((string) $row->getKey());
    }
}
