<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\CrmEntity;
use App\Enums\Notifications\NotificationReason;
use App\Models\Notification as NotificationRow;
use App\Models\TableLayout;
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

    public const string RENAMED = 'renamed';

    public const string UPDATED = 'updated';

    private function __construct(
        private readonly Workspace $workspace,
        private readonly User $actor,
        private readonly string $layoutName,
        private readonly CrmEntity $entity,
        private readonly string $change,
        private readonly ?string $previousName = null,
    ) {}

    public static function deleted(TableLayout $layout, User $actor): self
    {
        return self::about($layout, $actor, self::DELETED);
    }

    public static function unshared(TableLayout $layout, User $actor): self
    {
        return self::about($layout, $actor, self::UNSHARED);
    }

    public static function renamed(TableLayout $layout, User $actor, string $previousName): self
    {
        return self::about($layout, $actor, self::RENAMED, $previousName);
    }

    public static function updated(TableLayout $layout, User $actor): self
    {
        return self::about($layout, $actor, self::UPDATED);
    }

    // Copies the layout's values: the queued notice runs after a deleted layout's row is gone.
    private static function about(TableLayout $layout, User $actor, string $change, ?string $previousName = null): self
    {
        return new self($layout->loadMissing('workspace')->workspace, $actor, $layout->name, $layout->entity, $change, $previousName);
    }

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
        $facts = ['layout_name' => $this->layoutName, 'entity' => $this->entity->value, 'change' => $this->change];

        return $this->previousName === null ? $facts : $facts + ['previous_name' => $this->previousName];
    }

    public static function present(NotificationRow $row): FilamentNotification
    {
        $change = (string) data_get($row->data, 'change');

        throw_unless(in_array($change, [self::DELETED, self::UNSHARED, self::RENAMED, self::UPDATED], true), LogicException::class, "A table layout notice holds an unknown change [{$change}].");

        $entity = CrmEntity::from((string) data_get($row->data, 'entity'));
        $urls = resolve(CanonicalRecordUrl::class);
        $table = $urls->entityPluralLabel($entity);
        $url = $row->workspace instanceof Workspace ? $urls->listPageUrl($entity, $row->workspace) : null;
        $name = (string) data_get($row->data, 'layout_name');
        $actorName = $row->actor?->name;
        $replace = ['name' => $name, 'previous' => (string) data_get($row->data, 'previous_name')];

        return FilamentNotification::make((string) $row->getKey())
            ->title($actorName === null
                ? __("notifications.events.table_layout_changed.{$change}_title_without_actor", $replace)
                : __("notifications.events.table_layout_changed.{$change}_title", ['actor' => $actorName, ...$replace]))
            ->body(__("notifications.events.table_layout_changed.{$change}_body", ['table' => $table]))
            ->icon(match ($change) {
                self::DELETED => Heroicon::OutlinedTrash,
                self::UNSHARED => Heroicon::OutlinedLockClosed,
                default => Heroicon::OutlinedPencilSquare,
            })
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
