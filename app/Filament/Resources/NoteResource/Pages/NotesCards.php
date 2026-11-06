<?php

declare(strict_types=1);

namespace App\Filament\Resources\NoteResource\Pages;

use App\Enums\CreatedPeriod;
use App\Filament\Components\Tables\EmptyListDescription;
use App\Filament\Components\Tables\NoteCardColumn;
use App\Filament\Concerns\HasNoteHeaderActions;
use App\Filament\Concerns\HasViewSwitcher;
use App\Filament\Resources\NoteResource;
use App\Models\Note;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\On;

final class NotesCards extends ManageRecords
{
    use HasNoteHeaderActions;
    use HasViewSwitcher;

    protected static string $resource = NoteResource::class;

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['opportunities', 'creator']))
            ->emptyStateDescription(EmptyListDescription::unlessFiltered(__('filament/resources/note.empty_state.description')))
            ->columns([
                Stack::make([
                    NoteCardColumn::make('title')->searchable(),
                ]),
            ])
            ->contentGrid(['md' => 2])
            ->defaultPaginationPageOption(24)
            ->defaultGroup($this->createdPeriodGroup(), 'desc')
            ->groupingSettingsHidden()
            ->columnManager(false)
            ->recordAction('edit')
            ->toolbarActions([]);
    }

    #[On('ai-write-completed')]
    public function refreshOnAiWrite(): void
    {
        // Filament table auto-refreshes on Livewire re-render
    }

    private function createdPeriodGroup(): Group
    {
        return Group::make('created_at')
            ->titlePrefixedWithLabel(false)
            ->getKeyFromRecordUsing(fn (Note $record): CreatedPeriod => $this->createdPeriod($record))
            ->getTitleFromRecordUsing(function (Note $record): HtmlString {
                $period = $this->createdPeriod($record);

                return new HtmlString(sprintf(
                    '%s <span class="fi-ta-group-count">%d</span>',
                    e($period->getLabel()),
                    $this->createdPeriodCounts()[$period->value],
                ));
            });
    }

    private function createdPeriod(Note $note): CreatedPeriod
    {
        return $note->created_at === null
            ? CreatedPeriod::Earlier
            : CreatedPeriod::of($note->created_at, $this->viewerNow());
    }

    private function viewerNow(): CarbonImmutable
    {
        return once(fn (): CarbonImmutable => Date::now(FilamentTimezone::get()));
    }

    /**
     * @return array<string, int>
     */
    private function createdPeriodCounts(): array
    {
        return once(function (): array {
            $query = ($this->getFilteredTableQuery() ?? Note::query())->reorder()->toBase();
            $query->select([])->selectRaw('count(*) as earlier');

            foreach (CreatedPeriod::cases() as $period) {
                $start = $period->startsAt($this->viewerNow());

                if ($start instanceof CarbonImmutable) {
                    $query->selectRaw("count(*) filter (where notes.created_at >= ?) as {$period->value}", [$start->utc()]);
                }
            }

            $cumulative = (array) $query->first();
            $counts = [];
            $newer = 0;

            foreach (CreatedPeriod::cases() as $period) {
                $counts[$period->value] = (int) $cumulative[$period->value] - $newer;
                $newer = (int) $cumulative[$period->value];
            }

            return $counts;
        });
    }
}
