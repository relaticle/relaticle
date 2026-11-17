<?php

declare(strict_types=1);

namespace App\Livewire\App\Records;

use App\Livewire\BaseLivewireComponent;
use App\Models\Company;
use App\Models\People;
use App\Queries\Crm\DuplicateRecordQuery;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconSize;
use Illuminate\Contracts\View\View as ViewContract;
use Livewire\Attributes\Locked;

final class DuplicateRecordsPrompt extends BaseLivewireComponent
{
    public const int SHOWN = 10;

    #[Locked]
    public Company|People $record;

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (): string => $this->countLabel())
                ->icon('lucide-circle-alert')
                ->iconSize(IconSize::Small)
                ->iconColor('warning')
                ->contained(false)
                ->compact()
                ->collapsible()
                ->collapsed()
                ->schema([
                    View::make('livewire.app.records.duplicate-records-list')
                        ->viewData(fn (): array => ['duplicates' => array_slice($this->duplicates(), 0, self::SHOWN, preserve_keys: true)]),
                ])
                ->key('duplicates')
                ->extraAttributes(['data-duplicate-prompt' => true]),
        ]);
    }

    public function placeholder(): ViewContract
    {
        return view('livewire.app.records.duplicate-records-checking');
    }

    public function render(): ViewContract
    {
        return view('livewire.app.records.duplicate-records-prompt', [
            'hasDuplicates' => $this->duplicates() !== [],
        ]);
    }

    private function countLabel(): string
    {
        $count = count($this->duplicates());

        return $count > self::SHOWN
            ? __('filament/record-page.merge.duplicate.count_over', ['count' => self::SHOWN])
            : trans_choice('filament/record-page.merge.duplicate.count', $count);
    }

    /**
     * @return array<string, array{record: Company|People, value: string}>
     */
    private function duplicates(): array
    {
        // The prompt is advisory, so a failed scan hides it instead of breaking the record page.
        return once(fn (): array => rescue(
            fn (): array => resolve(DuplicateRecordQuery::class)->get($this->authUser(), $this->record, self::SHOWN + 1),
            [],
        ));
    }
}
