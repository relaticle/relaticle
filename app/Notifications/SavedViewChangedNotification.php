<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\CrmEntity;
use App\Enums\Notifications\NotificationReason;
use App\Enums\Notifications\SavedViewChange;
use App\Models\Notification as NotificationRow;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CanonicalRecordUrl;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Str;

final class SavedViewChangedNotification extends InboxNotification
{
    private function __construct(
        private readonly Workspace $workspace,
        private readonly User $actor,
        private readonly string $viewName,
        private readonly CrmEntity $entity,
        private readonly SavedViewChange $change,
        private readonly ?string $previousName = null,
    ) {}

    public static function deleted(SavedView $view, User $actor): self
    {
        return self::about($view, $actor, SavedViewChange::Deleted);
    }

    public static function unshared(SavedView $view, User $actor): self
    {
        return self::about($view, $actor, SavedViewChange::Unshared);
    }

    public static function renamed(SavedView $view, User $actor, string $previousName): self
    {
        return self::about($view, $actor, SavedViewChange::Renamed, $previousName);
    }

    public static function updated(SavedView $view, User $actor): self
    {
        return self::about($view, $actor, SavedViewChange::Updated);
    }

    // Copies the view's values: the queued notice runs after a deleted view's row is gone.
    private static function about(SavedView $view, User $actor, SavedViewChange $change, ?string $previousName = null): self
    {
        return new self($view->loadMissing('workspace')->workspace, $actor, $view->name, $view->entity, $change, $previousName);
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
        $facts = ['view_name' => $this->viewName, 'entity' => $this->entity->value, 'change' => $this->change->value];

        return $this->previousName === null ? $facts : $facts + ['previous_name' => $this->previousName];
    }

    public static function present(NotificationRow $row): FilamentNotification
    {
        $change = SavedViewChange::from((string) data_get($row->data, 'change'));

        $entity = CrmEntity::from((string) data_get($row->data, 'entity'));
        $urls = resolve(CanonicalRecordUrl::class);
        $table = Filament::getPanel('app')->getModelResource($entity->model())::getPluralModelLabel();
        $url = $row->workspace instanceof Workspace ? $urls->listPageUrl($entity, $row->workspace) : null;
        $actorName = $row->actor?->name;
        // The panel renders a title as HTML, and a member types the names.
        $replace = ['name' => e((string) data_get($row->data, 'view_name')), 'previous' => e((string) data_get($row->data, 'previous_name'))];

        return FilamentNotification::make((string) $row->getKey())
            ->title($actorName === null
                ? __("notifications.events.saved_view_changed.{$change->value}_title_without_actor", $replace)
                : __("notifications.events.saved_view_changed.{$change->value}_title", ['actor' => e($actorName), ...$replace]))
            ->body(__("notifications.events.saved_view_changed.{$change->value}_body", ['table' => $table]))
            ->icon($change->icon())
            ->iconColor('gray')
            ->actions($url === null ? [] : [
                Action::make('view')
                    ->button()
                    ->label(__('notifications.events.saved_view_changed.action', ['table' => Str::ucfirst($table)]))
                    ->url($url)
                    ->markAsRead(),
            ]);
    }
}
