<?php

declare(strict_types=1);

namespace App\Filament\Components\Infolists;

use App\Enums\CustomFieldType;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\People;
use Filament\Infolists\Components\Entry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Relaticle\CustomFields\Facades\CustomFields;

final readonly class RecordDetails
{
    private const int VISIBLE_DETAIL_COUNT = 8;

    /**
     * @param  array<int, Entry>  $nativeEntries
     */
    public static function section(Model $record, array $nativeEntries): Section
    {
        $entries = self::entries($record, $nativeEntries)
            ->values()
            ->map(fn (Entry $entry): Entry => $entry
                ->inlineLabel()
                ->columnSpan(['default' => 'full', 'lg' => 'full']));

        $visible = $entries->take(self::VISIBLE_DETAIL_COUNT)->all();
        $overflow = $entries->skip(self::VISIBLE_DETAIL_COUNT)->all();

        return Section::make(__('filament/record-page.sections.details'))
            ->contained(false)
            ->collapsible()
            ->persistCollapsed()
            ->id('record-details')
            ->schema([
                Group::make([
                    ...$visible,
                    ...self::overflowDetails($overflow),
                ])
                    ->dense()
                    ->extraAttributes(['x-data' => '{ showAllDetails: false }']),
            ])
            ->extraAttributes(['class' => 'fi-record-rail-section']);
    }

    /**
     * @param  array<int, Entry>  $entries
     */
    public static function highlights(array $entries): Section
    {
        return Section::make(__('filament/record-page.overview.highlights'))
            ->contained(false)
            ->schema([
                Grid::make(['default' => 1, 'sm' => 2, 'xl' => 3])->schema(array_map(
                    fn (Entry $entry): Entry => $entry
                        ->columnSpan(1)
                        ->extraEntryWrapperAttributes(['class' => 'fi-record-highlight'], merge: true),
                    $entries,
                )),
            ])
            ->extraAttributes(['class' => 'fi-record-overview-section fi-record-highlights']);
    }

    public static function recordInfo(): Section
    {
        return Section::make(__('filament/record-page.sections.record_info'))
            ->contained(false)
            ->collapsible()
            ->collapsed()
            ->dense()
            ->inlineLabel()
            ->schema([
                RecordChipEntry::make('creator.name')
                    ->label(__('filament/record-page.fields.created_by'))
                    ->beforeLabel(Icon::make('lucide-circle-user'))
                    ->visible(fn (Company|People|Opportunity $record): bool => self::hasMemberCreator($record)),
                TextEntry::make('created_by')
                    ->label(__('filament/record-page.fields.created_by'))
                    ->beforeLabel(Icon::make('lucide-circle-user'))
                    ->hidden(fn (Company|People|Opportunity $record): bool => self::hasMemberCreator($record)),
                TextEntry::make('created_at')
                    ->label(__('filament/record-page.fields.created_at'))
                    ->beforeLabel(Icon::make('lucide-calendar'))
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->label(__('filament/record-page.fields.updated_at'))
                    ->beforeLabel(Icon::make('lucide-refresh-cw'))
                    ->dateTime(),
            ])
            ->extraAttributes(['class' => 'fi-record-rail-section']);
    }

    /**
     * @param  array<int, Entry>  $nativeEntries
     * @return Collection<string, Entry>
     */
    public static function entries(Model $record, array $nativeEntries): Collection
    {
        $customFields = CustomFields::infolist()->withoutSections()->forModel($record);

        $customFieldIcons = $customFields->getFields()
            ->mapWithKeys(fn (CustomField $field): array => [$field->getFieldName() => CustomFieldType::tryFrom($field->type)?->icon()]);

        return collect($nativeEntries)
            ->concat($customFields->values()->map(fn (Entry $entry): Entry => $entry
                ->beforeLabel(Icon::make($customFieldIcons->get($entry->getName()) ?? 'lucide-layout-grid'))))
            ->map(fn (Entry $entry): Entry => $entry->placeholder(__('filament/record-page.empty')))
            ->keyBy(fn (Entry $entry): string => $entry->getName());
    }

    /**
     * @param  array<int, Entry>  $overflow
     * @return array<int, Component>
     */
    private static function overflowDetails(array $overflow): array
    {
        if ($overflow === []) {
            return [];
        }

        return [
            Group::make($overflow)->dense()->extraAttributes([
                'x-show' => 'showAllDetails',
                'x-cloak' => true,
            ]),
            View::make('filament.app.record-details-toggle'),
        ];
    }

    private static function hasMemberCreator(Company|People|Opportunity $record): bool
    {
        return ! $record->isSystemCreated() && $record->creator !== null;
    }
}
