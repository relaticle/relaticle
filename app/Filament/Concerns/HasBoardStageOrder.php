<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Actions\Views\ReorderBoardStages;
use App\Data\BoardStateData;
use App\Models\TablePreference;
use App\Queries\Views\StoredTablePreferenceQuery;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

trait HasBoardStageOrder
{
    private ?BoardStateData $boardState = null;

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

        resolve(ReorderBoardStages::class)->execute($this->viewSwitcherUser(), $this->viewSwitcherWorkspace(), $this->viewSwitcherEntity(), $known);

        $this->boardState = null;
        $this->board = $this->board($this->makeBoard());
    }

    public function boardViewSettings(): Htmlable
    {
        return new HtmlString(view('filament.app.board-view-settings', [
            'stages' => $this->boardStageList(),
            'heading' => $this->boardStageHeading(),
        ])->render());
    }

    /**
     * @param  Collection<int, array{id: mixed, custom_field_id: mixed, name: mixed, color: string}>  $stages
     * @return Collection<int, array{id: mixed, custom_field_id: mixed, name: mixed, color: string}>
     */
    protected function inMemberOrder(Collection $stages): Collection
    {
        $order = $this->boardState()->orderStages(array_values($stages->map(static fn (array $stage): string => (string) $stage['id'])->all()));

        return $stages
            ->sortBy(static fn (array $stage): int|false => array_search((string) $stage['id'], $order, true))
            ->values();
    }

    private function boardState(): BoardStateData
    {
        $preference = resolve(StoredTablePreferenceQuery::class)
            ->get($this->viewSwitcherUser(), $this->viewSwitcherWorkspace(), $this->viewSwitcherEntity());

        return $this->boardState ??= $preference instanceof TablePreference ? $preference->board_state : new BoardStateData;
    }

    /**
     * @return Collection<int, array{id: mixed, custom_field_id: mixed, name: mixed, color: string}>
     */
    abstract private function boardStageList(): Collection;

    abstract private function boardStageHeading(): string;
}
