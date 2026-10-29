<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\CrmEntity;
use App\Enums\Notifications\NotificationReason;
use App\Models\Notification as NotificationRow;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CanonicalRecordUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use LogicException;

final class TableLayoutChangedNotification extends InboxNotification
{
    public const string DELETED = 'deleted';

    public const string UNSHARED = 'unshared';

    public function __construct(
        private readonly Workspace $workspace,
        private readonly User $actor,
        private readonly string $layoutName,
        private readonly CrmEntity $entity,
        private readonly string $change,
    ) {}

    public function workspace(): Workspace
    {
        return $this->workspace;
    }

    public function subject(): Workspace
    {
        return $this->workspace;
    }

    public function actor(): User
    {
        return $this->actor;
    }

    public function reason(): NotificationReason
    {
        return NotificationReason::System;
    }

    /** @return array<string, mixed> */
    public function facts(): array
    {
        return ['layout_name' => $this->layoutName, 'entity' => $this->entity->value, 'change' => $this->change];
    }

    public static function present(NotificationRow $row): FilamentNotification
    {
        $change = (string) data_get($row->data, 'change');

        throw_unless(in_array($change, [self::DELETED, self::UNSHARED], true), LogicException::class, "A table layout notice holds an unknown change [{$change}].");

        $entity = CrmEntity::from((string) data_get($row->data, 'entity'));
        $urls = resolve(CanonicalRecordUrl::class);
        $table = $urls->listLabel($entity);
        $url = $row->workspace instanceof Workspace ? $urls->listUrl($entity, $row->workspace) : null;
        $name = (string) data_get($row->data, 'layout_name');
        $actorName = $row->actor?->name;

        return FilamentNotification::make((string) $row->getKey())
            ->title($actorName === null
                ? __("notifications.events.table_layout_changed.{$change}_title_without_actor", ['name' => $name])
                : __("notifications.events.table_layout_changed.{$change}_title", ['actor' => $actorName, 'name' => $name]))
            ->body(__("notifications.events.table_layout_changed.{$change}_body", ['table' => $table]))
            ->icon($change === self::DELETED ? Heroicon::OutlinedTrash : Heroicon::OutlinedLockClosed)
            ->iconColor('gray')
            ->actions($url === null ? [] : [
                Action::make('view')
                    ->button()
                    ->label(__('notifications.events.table_layout_changed.action', ['table' => Str::ucfirst($table)]))
                    ->url($url)
                    ->markAsRead(),
            ]);
    }
}
