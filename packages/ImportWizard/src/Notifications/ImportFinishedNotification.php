<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Notifications;

use App\Enums\Notifications\NotificationReason;
use App\Models\Notification as NotificationRow;
use App\Models\Workspace;
use App\Notifications\InboxNotification;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Facades\URL;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportOutcome;
use Relaticle\ImportWizard\Filament\Pages\ImportHistory;
use Relaticle\ImportWizard\Models\Import;

final class ImportFinishedNotification extends InboxNotification
{
    private const array COUNTS = ['created', 'updated', 'skipped', 'failed'];

    public function __construct(
        private readonly Import $import,
        private readonly ImportOutcome $outcome,
    ) {}

    public function workspace(): Workspace
    {
        return $this->import->workspace;
    }

    public function subject(): Import
    {
        return $this->import;
    }

    public function reason(): NotificationReason
    {
        return NotificationReason::System;
    }

    public function groupKey(): string
    {
        return "import:{$this->import->getKey()}";
    }

    public function collapsesReadRows(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function facts(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'entity' => $this->import->entity_type->value,
            'created' => $this->import->created_rows,
            'updated' => $this->import->updated_rows,
            'skipped' => $this->import->skipped_rows,
            'failed' => $this->import->failed_rows,
        ];
    }

    public static function present(NotificationRow $row): FilamentNotification
    {
        $outcome = ImportOutcome::from((string) data_get($row->data, 'outcome'));
        $entity = ImportEntityType::from((string) data_get($row->data, 'entity'));

        return FilamentNotification::make((string) $row->getKey())
            ->title(__("import-wizard-new::notifications.finished.title.{$outcome->value}", ['entity' => $entity->label()]))
            ->body(self::summary($row))
            ->status($outcome->color())
            ->actions(self::actions($row));
    }

    private static function summary(NotificationRow $row): ?string
    {
        $parts = collect(self::COUNTS)
            ->filter(fn (string $key): bool => (int) data_get($row->data, $key) > 0)
            ->map(fn (string $key): string => __("import-wizard-new::notifications.finished.counts.{$key}", [
                'count' => number_format((int) data_get($row->data, $key)),
            ]));

        return $parts->isEmpty() ? null : $parts->implode(', ');
    }

    /** @return list<Action> */
    private static function actions(NotificationRow $row): array
    {
        $workspace = $row->workspace;

        if (! $workspace instanceof Workspace) {
            return [];
        }

        $actions = [
            Action::make('view')
                ->button()
                ->label(__('import-wizard-new::notifications.finished.actions.view'))
                ->url(ImportHistory::getUrl(panel: 'app', tenant: $workspace))
                ->markAsRead(),
        ];

        if ((int) data_get($row->data, 'failed') > 0) {
            $actions[] = Action::make('download')
                ->button()
                ->color('gray')
                ->label(__('import-wizard-new::notifications.finished.actions.download'))
                ->url(URL::temporarySignedRoute(
                    'import-history.failed-rows.download',
                    now()->addHour(),
                    ['import' => $row->subject_id],
                ), shouldOpenInNewTab: true)
                ->markAsRead();
        }

        return $actions;
    }
}
