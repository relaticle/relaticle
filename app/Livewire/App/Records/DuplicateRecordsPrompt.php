<?php

declare(strict_types=1);

namespace App\Livewire\App\Records;

use App\Livewire\BaseLivewireComponent;
use App\Models\Company;
use App\Models\People;
use App\Queries\Crm\DuplicateRecordQuery;
use Filament\Actions\Action;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconSize;
use Filament\Support\Enums\Size;
use Illuminate\Contracts\View\View as ViewContract;
use Livewire\Attributes\Locked;

final class DuplicateRecordsPrompt extends BaseLivewireComponent
{
    #[Locked]
    public Company|People $record;

    public static function dismissedKey(Company|People $record): string
    {
        return 'dismissed_duplicate_prompts.'.$record->getKey();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (): string => trans_choice('filament/record-page.merge.duplicate.count', count($this->duplicates())))
                ->icon('lucide-circle-alert')
                ->iconSize(IconSize::Small)
                ->iconColor('warning')
                ->contained(false)
                ->compact()
                ->collapsible()
                ->collapsed()
                ->headerActions([
                    Action::make('dismissDuplicate')
                        ->label(__('filament/record-page.merge.duplicate.dismiss'))
                        ->icon('lucide-x')
                        ->iconSize(IconSize::Small)
                        ->iconButton()
                        ->color('gray')
                        ->size(Size::Small)
                        ->action(function (): void {
                            session()->put(self::dismissedKey($this->record), true);
                        }),
                ])
                ->schema([
                    View::make('livewire.app.records.duplicate-records-list')
                        ->viewData(fn (): array => ['duplicates' => $this->duplicates()]),
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

    /**
     * @return array<string, array{record: Company|People, value: string}>
     */
    private function duplicates(): array
    {
        if (session()->has(self::dismissedKey($this->record))) {
            return [];
        }

        return once(fn (): array => resolve(DuplicateRecordQuery::class)->get($this->authUser(), $this->record));
    }
}
