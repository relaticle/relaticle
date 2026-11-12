<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Actions\Views\ReorderBoardStages;
use App\Data\BoardStateData;
use App\Models\SavedView;
use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

trait HasBoardStageOrder
{
    /**
     * @param  list<mixed>  $optionIds
     */
    public function reorderBoardStages(array $optionIds): void
    {
        $stageIds = $this->boardStageList()->map(static fn (array $stage): string => (string) $stage['id']);
        $known = array_values(array_intersect(array_filter($optionIds, is_string(...)), $stageIds->all()));

        if ($known === []) {
            return;
        }

        $saved = $this->writingBoardState(fn (?SavedView $view): BoardStateData => resolve(ReorderBoardStages::class)->execute($this->viewSwitcherUser(), $this->viewSwitcherWorkspace(), $this->viewSwitcherEntity(), $known, $view));

        if (! $saved instanceof BoardStateData) {
            return;
        }

        $this->forgetStoredBoardState();
        $this->board = $this->board($this->makeBoard());
    }

    public function boardViewSettings(): Htmlable
    {
        return new HtmlString(view('filament.app.board-view-settings', [
            'stages' => $this->boardStageList(),
            'heading' => $this->boardStageHeading(),
            'groupings' => $this->boardGroupings(),
            'grouping' => $this->getBoard()->getActiveGrouping()?->getName(),
        ])->render());
    }

    /**
     * @param  Collection<int, array{id: mixed, custom_field_id: mixed, name: mixed, color: string}>  $stages
     * @return Collection<int, array{id: mixed, custom_field_id: mixed, name: mixed, color: string}>
     */
    protected function inMemberOrder(Collection $stages): Collection
    {
        $order = $this->storedBoardState()->orderStages(array_values($stages->map(static fn (array $stage): string => (string) $stage['id'])->all()));

        return $stages
            ->sortBy(static fn (array $stage): int|false => array_search((string) $stage['id'], $order, true))
            ->values();
    }

    /**
     * @return Collection<int, array{id: mixed, custom_field_id: mixed, name: mixed, color: string}>
     */
    abstract private function boardStageList(): Collection;

    abstract private function boardStageHeading(): string;

    /**
     * @return list<array{id: string, label: string, icon: ?string}>
     */
    abstract protected function boardGroupings(): array;

    abstract protected function storedBoardState(): BoardStateData;

    abstract protected function forgetStoredBoardState(): void;

    abstract protected function activeBoardView(): ?SavedView;

    /**
     * @param  Closure(?SavedView): BoardStateData  $write
     */
    abstract protected function writingBoardState(Closure $write): ?BoardStateData;
}
