<?php

declare(strict_types=1);

namespace App\Filament\Tables\Concerns;

use App\Actions\TableLayouts\CreateTableLayout;
use App\Actions\TableLayouts\DeleteTableLayout;
use App\Actions\TableLayouts\SetDefaultTableLayout;
use App\Actions\TableLayouts\UpdateTableLayout;
use App\Data\TableStateData;
use App\Models\TableLayout;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

trait HasTableLayouts
{
    /** @var Collection<int, TableLayout>|null */
    private ?Collection $visibleTableLayouts = null;

    /**
     * @return Collection<int, TableLayout>
     */
    protected function getVisibleTableLayouts(): Collection
    {
        return $this->visibleTableLayouts ??= TableLayout::query()
            ->visibleTo($this->tableLayoutUser(), $this->tableLayoutWorkspace()->getKey(), $this->tableEntity())
            ->with('user:id,name')
            ->orderByRaw('lower(name)')
            ->get();
    }

    protected function getActiveTableLayout(): ?TableLayout
    {
        return $this->getVisibleTableLayouts()->find($this->activeTableLayoutId);
    }

    public function isTableLayoutModified(): bool
    {
        return $this->activeTableLayoutId !== null
            && $this->currentTableState()->withoutWidths()->hash() !== $this->appliedTableLayoutHash;
    }

    protected function canUpdateActiveTableLayout(): bool
    {
        $layout = $this->getActiveTableLayout();

        return $layout instanceof TableLayout && $this->canUpdateTableLayout($layout);
    }

    protected function canUpdateTableLayout(TableLayout $layout): bool
    {
        return $this->tableLayoutUser()->can('update', $layout);
    }

    public function applySavedLayout(string $layoutId): void
    {
        $layout = $this->visibleTableLayout($layoutId);

        if (! $layout instanceof TableLayout) {
            $this->handleMissingTableLayout($layoutId);

            return;
        }

        $this->applyTableState($layout->state);
        $this->activeTableLayoutId = $layout->getKey();
        $this->appliedTableLayoutHash = $this->currentTableState()->withoutWidths()->hash();
    }

    public function revertTableLayout(): void
    {
        if ($this->activeTableLayoutId !== null) {
            $this->applySavedLayout($this->activeTableLayoutId);
        }
    }

    public function saveTableLayout(): void
    {
        $layout = $this->getActiveTableLayout();

        if (! $layout instanceof TableLayout) {
            $this->handleMissingTableLayout($this->activeTableLayoutId);

            return;
        }

        $layout = resolve(UpdateTableLayout::class)->execute($this->tableLayoutUser(), $layout, ['state' => $this->currentTableState()]);
        $this->appliedTableLayoutHash = $this->currentTableState()->withoutWidths()->hash();
        $this->visibleTableLayouts = null;

        Notification::make()->success()->title(__('filament/panel.tables.layouts.saved', ['name' => $layout->name]))->send();
    }

    public function toggleDefaultTableLayout(string $layoutId): void
    {
        $layout = $this->visibleTableLayout($layoutId) ?? abort(404);
        $default = $this->defaultTableLayoutId === $layout->getKey() ? null : $layout;

        resolve(SetDefaultTableLayout::class)->execute($this->tableLayoutUser(), $this->tableLayoutWorkspace(), $this->tableEntity(), $default);
        $this->defaultTableLayoutId = $default?->getKey();
    }

    public function useStandardTableLayout(): void
    {
        $this->applyTableState(new TableStateData);
        $this->activeTableLayoutId = null;
        $this->appliedTableLayoutHash = null;
    }

    public function saveTableLayoutAsAction(): Action
    {
        return Action::make('saveTableLayoutAs')
            ->label(__('filament/panel.tables.layouts.save_as'))
            ->modalHeading(__('filament/panel.tables.layouts.save_as'))
            ->modalWidth('md')
            ->modalSubmitActionLabel(__('filament/panel.tables.layouts.save'))
            ->schema([$this->tableLayoutNameInput()])
            ->action(function (array $data, Schema $schema): void {
                $layout = $this->withLayoutNameErrors($schema, fn (): TableLayout => resolve(CreateTableLayout::class)->execute(
                    $this->tableLayoutUser(),
                    $this->tableLayoutWorkspace(),
                    $this->tableEntity(),
                    $data['name'],
                    $this->currentTableState(),
                ));

                $this->activeTableLayoutId = $layout->getKey();
                $this->appliedTableLayoutHash = $this->currentTableState()->withoutWidths()->hash();
                $this->visibleTableLayouts = null;

                Notification::make()->success()->title(__('filament/panel.tables.layouts.created', ['name' => $layout->name]))->send();
            });
    }

    public function renameTableLayoutAction(): Action
    {
        return Action::make('renameTableLayout')
            ->label(__('filament/panel.tables.layouts.rename'))
            ->modalHeading(__('filament/panel.tables.layouts.rename'))
            ->modalWidth('md')
            ->modalSubmitActionLabel(__('filament/panel.tables.layouts.save'))
            ->visible(fn (array $arguments): bool => $this->canUpdateArgumentTableLayout($arguments))
            ->fillForm(fn (array $arguments): array => ['name' => $this->argumentTableLayout($arguments)?->name])
            ->schema([$this->tableLayoutNameInput()])
            ->action(function (array $arguments, array $data, Schema $schema): void {
                $layout = $this->argumentTableLayout($arguments) ?? abort(404);

                $this->withLayoutNameErrors($schema, fn (): TableLayout => resolve(UpdateTableLayout::class)->execute(
                    $this->tableLayoutUser(),
                    $layout,
                    ['name' => $data['name']],
                ));

                $this->visibleTableLayouts = null;
            });
    }

    public function shareTableLayoutAction(): Action
    {
        return Action::make('shareTableLayout')
            ->label(__('filament/panel.tables.layouts.share'))
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-user-group')
            ->modalHeading(fn (array $arguments): string => __('filament/panel.tables.layouts.share_heading', ['name' => $this->argumentTableLayout($arguments)?->name]))
            ->modalDescription(fn (): string => __('filament/panel.tables.layouts.share_description', ['workspace' => $this->tableLayoutWorkspace()->name]))
            ->modalSubmitActionLabel(__('filament/panel.tables.layouts.share_confirm'))
            ->visible(fn (array $arguments): bool => $this->canUpdateArgumentTableLayout($arguments) && ! $this->argumentTableLayout($arguments)?->is_shared)
            ->action(fn (array $arguments) => $this->setTableLayoutSharing($this->argumentTableLayout($arguments) ?? abort(404), true));
    }

    public function stopSharingTableLayoutAction(): Action
    {
        return Action::make('stopSharingTableLayout')
            ->label(__('filament/panel.tables.layouts.unshare'))
            ->requiresConfirmation()
            ->modalHidden(fn (array $arguments): bool => $this->argumentTableLayout($arguments)?->user_id === $this->tableLayoutUser()->getKey())
            ->modalIcon('heroicon-o-lock-closed')
            ->modalHeading(fn (array $arguments): string => __('filament/panel.tables.layouts.unshare_heading', ['name' => $this->argumentTableLayout($arguments)?->name]))
            ->modalDescription(fn (array $arguments): string => __('filament/panel.tables.layouts.unshare_description', ['owner' => $this->argumentTableLayout($arguments)?->user?->name]))
            ->modalSubmitActionLabel(__('filament/panel.tables.layouts.unshare'))
            ->visible(fn (array $arguments): bool => $this->canUpdateArgumentTableLayout($arguments) && (bool) $this->argumentTableLayout($arguments)?->is_shared)
            ->action(fn (array $arguments) => $this->setTableLayoutSharing($this->argumentTableLayout($arguments) ?? abort(404), false));
    }

    public function deleteTableLayoutAction(): Action
    {
        return Action::make('deleteTableLayout')
            ->label(__('filament/panel.tables.layouts.delete'))
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => __('filament/panel.tables.layouts.delete_heading', ['name' => $this->argumentTableLayout($arguments)?->name]))
            ->modalDescription(function (array $arguments): string {
                $layout = $this->argumentTableLayout($arguments);

                return $layout instanceof TableLayout && $layout->user_id !== $this->tableLayoutUser()->getKey()
                    ? __('filament/panel.tables.layouts.delete_description_other', ['owner' => $layout->user?->name])
                    : __('filament/panel.tables.layouts.delete_description');
            })
            ->visible(fn (array $arguments): bool => $this->canUpdateArgumentTableLayout($arguments))
            ->action(function (array $arguments): void {
                $layout = $this->argumentTableLayout($arguments) ?? abort(404);

                resolve(DeleteTableLayout::class)->execute($this->tableLayoutUser(), $layout);

                $this->forgetActiveTableLayout($layout);
                $this->defaultTableLayoutId = $this->defaultTableLayoutId === $layout->getKey() ? null : $this->defaultTableLayoutId;
                $this->visibleTableLayouts = null;

                Notification::make()->success()->title(__('filament/panel.tables.layouts.deleted', ['name' => $layout->name]))->send();
            });
    }

    private function setTableLayoutSharing(TableLayout $layout, bool $isShared): void
    {
        $layout = resolve(UpdateTableLayout::class)->execute($this->tableLayoutUser(), $layout, ['is_shared' => $isShared]);
        $this->visibleTableLayouts = null;

        if ($this->tableLayoutUser()->cannot('view', $layout)) {
            $this->forgetActiveTableLayout($layout);
            $this->defaultTableLayoutId = $this->defaultTableLayoutId === $layout->getKey() ? null : $this->defaultTableLayoutId;
        }

        Notification::make()->success()->title(__($isShared ? 'filament/panel.tables.layouts.shared' : 'filament/panel.tables.layouts.unshared', ['name' => $layout->name]))->send();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function argumentTableLayout(array $arguments): ?TableLayout
    {
        $layoutId = $arguments['layout'] ?? null;

        return is_string($layoutId) ? $this->visibleTableLayout($layoutId) : null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function canUpdateArgumentTableLayout(array $arguments): bool
    {
        $layout = $this->argumentTableLayout($arguments);

        return $layout instanceof TableLayout && $this->canUpdateTableLayout($layout);
    }

    private function handleMissingTableLayout(?string $layoutId): void
    {
        if ($this->activeTableLayoutId === $layoutId) {
            $this->activeTableLayoutId = null;
            $this->appliedTableLayoutHash = null;
        }

        $this->defaultTableLayoutId = $this->defaultTableLayoutId === $layoutId ? null : $this->defaultTableLayoutId;
        $this->visibleTableLayouts = null;

        Notification::make()->warning()->title(__('filament/panel.tables.layouts.unavailable'))->send();
    }

    private function forgetActiveTableLayout(TableLayout $layout): void
    {
        if ($this->activeTableLayoutId !== $layout->getKey()) {
            return;
        }

        $this->activeTableLayoutId = null;
        $this->appliedTableLayoutHash = null;
    }

    private function tableLayoutNameInput(): TextInput
    {
        return TextInput::make('name')
            ->label(__('filament/panel.tables.layouts.name'))
            ->required()
            ->maxLength(255)
            ->autofocus();
    }

    /**
     * @param  Closure(): TableLayout  $write
     */
    private function withLayoutNameErrors(Schema $schema, Closure $write): TableLayout
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                "{$schema->getStatePath()}.name" => [__('filament/panel.tables.layouts.name_taken')],
            ]);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                "{$schema->getStatePath()}.name" => $exception->errors()['name'] ?? [],
            ]);
        }
    }

    private function tableLayoutUser(): User
    {
        /** @var User */
        return auth()->user();
    }

    private function tableLayoutWorkspace(): Workspace
    {
        /** @var Workspace */
        return Filament::getTenant();
    }
}
