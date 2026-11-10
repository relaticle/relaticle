<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\CrmEntity;
use App\Enums\Notifications\NotificationReason;
use App\Enums\Notifications\TableLayoutChange;
use App\Models\Notification as NotificationRow;
use App\Models\TableLayout;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CanonicalRecordUrl;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Str;

final class TableLayoutChangedNotification extends InboxNotification
{
    private function __construct(
        private readonly Workspace $workspace,
        private readonly User $actor,
        private readonly string $layoutName,
        private readonly CrmEntity $entity,
        private readonly TableLayoutChange $change,
        private readonly ?string $previousName = null,
    ) {}

    public static function deleted(TableLayout $layout, User $actor): self
    {
        return self::about($layout, $actor, TableLayoutChange::Deleted);
    }

    public static function unshared(TableLayout $layout, User $actor): self
    {
        return self::about($layout, $actor, TableLayoutChange::Unshared);
    }

    public static function renamed(TableLayout $layout, User $actor, string $previousName): self
    {
        return self::about($layout, $actor, TableLayoutChange::Renamed, $previousName);
    }

    public static function updated(TableLayout $layout, User $actor): self
    {
        return self::about($layout, $actor, TableLayoutChange::Updated);
    }

    // Copies the layout's values: the queued notice runs after a deleted layout's row is gone.
    private static function about(TableLayout $layout, User $actor, TableLayoutChange $change, ?string $previousName = null): self
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
        $facts = ['layout_name' => $this->layoutName, 'entity' => $this->entity->value, 'change' => $this->change->value];

        return $this->previousName === null ? $facts : $facts + ['previous_name' => $this->previousName];
    }

    public static function present(NotificationRow $row): FilamentNotification
    {
        $change = TableLayoutChange::from((string) data_get($row->data, 'change'));

        $entity = CrmEntity::from((string) data_get($row->data, 'entity'));
        $urls = resolve(CanonicalRecordUrl::class);
        $table = Filament::getPanel('app')->getModelResource($entity->model())::getPluralModelLabel();
        $url = $row->workspace instanceof Workspace ? $urls->listPageUrl($entity, $row->workspace) : null;
        $actorName = $row->actor?->name;
        // The panel renders a title as HTML, and a member types the names.
        $replace = ['name' => e((string) data_get($row->data, 'layout_name')), 'previous' => e((string) data_get($row->data, 'previous_name'))];

        return FilamentNotification::make((string) $row->getKey())
            ->title($actorName === null
                ? __("notifications.events.table_layout_changed.{$change->value}_title_without_actor", $replace)
                : __("notifications.events.table_layout_changed.{$change->value}_title", ['actor' => e($actorName), ...$replace]))
            ->body(__("notifications.events.table_layout_changed.{$change->value}_body", ['table' => $table]))
            ->icon($change->icon())
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
