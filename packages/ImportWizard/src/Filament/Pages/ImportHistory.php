<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Pages;

use App\Filament\Pages\Concerns\HasWorkspaceSettingsNavigation;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\URL;
use Relaticle\ImportWizard\Actions\MarkImportFailed;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Models\Import;

final class ImportHistory extends Page implements HasTable
{
    use HasWorkspaceSettingsNavigation;
    use InteractsWithTable;

    protected string $view = 'import-wizard-new::filament.pages.import-history';

    protected static string|null|BackedEnum $navigationIcon = 'lucide-clock';

    protected static ?int $navigationSort = 100;

    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationLabel(): string
    {
        return __('import-wizard-new::history.title');
    }

    public function getTitle(): string
    {
        return __('import-wizard-new::history.title');
    }

    public static function canAccess(): bool
    {
        return ImportPage::canAccess();
    }

    private function actingUser(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Import::query()
                    ->forWorkspace((string) filament()->getTenant()?->getKey())
                    ->whereIn('status', [ImportStatus::Completed, ImportStatus::Failed, ImportStatus::Importing])
                    ->latest()
            )
            ->columns([
                TextColumn::make('entity_type')
                    ->label(__('import-wizard-new::history.columns.type'))
                    ->badge()
                    ->formatStateUsing(fn (ImportEntityType $state): string => $state->label())
                    ->icon(fn (ImportEntityType $state): string => $state->icon()),

                TextColumn::make('file_name')
                    ->label(__('import-wizard-new::history.columns.file'))
                    ->searchable()
                    ->limit(30),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (ImportStatus $state): string => match ($state) {
                        ImportStatus::Completed => 'success',
                        ImportStatus::Failed => 'danger',
                        ImportStatus::Importing => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('total_rows')
                    ->label(__('import-wizard-new::history.columns.total'))
                    ->numeric(),

                TextColumn::make('created_rows')
                    ->label(__('import-wizard-new::history.columns.created'))
                    ->numeric()
                    ->color('success'),

                TextColumn::make('updated_rows')
                    ->label(__('import-wizard-new::history.columns.updated'))
                    ->numeric()
                    ->color('info'),

                TextColumn::make('skipped_rows')
                    ->label(__('import-wizard-new::history.columns.skipped'))
                    ->numeric()
                    ->color('gray'),

                TextColumn::make('failed_rows')
                    ->label(__('import-wizard-new::history.columns.failed'))
                    ->numeric()
                    ->color('danger'),

                TextColumn::make('user.name')
                    ->label(__('import-wizard-new::history.columns.user')),

                TextColumn::make('created_at')
                    ->label(__('import-wizard-new::history.columns.date'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('entity_type')
                    ->options(ImportEntityType::class),

                SelectFilter::make('status')
                    ->options(collect([ImportStatus::Completed, ImportStatus::Failed, ImportStatus::Importing])
                        ->mapWithKeys(fn (ImportStatus $status): array => [$status->value => $status->getLabel()])
                        ->all()),
            ])
            ->actions([
                Action::make('downloadFailedRows')
                    ->label(__('import-wizard-new::history.actions.failed_rows'))
                    ->icon('lucide-download')
                    ->color('danger')
                    ->url(fn (Import $record): string => URL::temporarySignedRoute(
                        'import-history.failed-rows.download',
                        now()->addHour(),
                        ['import' => $record],
                    ), shouldOpenInNewTab: true)
                    ->visible(fn (Import $record): bool => $record->failedRows()->exists()),

                // A worker that dies between claiming the import and finishing it leaves the
                // row on "importing" with no way out: the wizard refuses to re-dispatch a
                // record in that state, so the user is stuck with a spinner and no recourse.
                // Only offered once the run is old enough that it cannot still be working.
                Action::make('markFailed')
                    ->label(__('import-wizard-new::history.actions.mark_failed'))
                    ->icon('lucide-triangle-exclamation-point')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('import-wizard-new::history.actions.mark_failed_description'))
                    ->action(fn (Import $record): Import => resolve(MarkImportFailed::class)->execute($this->actingUser(), $record))
                    ->visible(fn (Import $record): bool => $record->isStalled()),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('10s');
    }
}
