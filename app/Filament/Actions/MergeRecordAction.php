<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Merge\MergeCompanies;
use App\Models\Company;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class MergeRecordAction extends Action
{
    public static function getDefaultName(): string
    {
        return 'mergeRecord';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('filament/record-page.actions.merge_record'))
            ->icon('lucide-merge')
            ->visible(fn (Model $record): bool => $record instanceof Company
                && Gate::allows('update', $record)
                && Gate::allows('delete', $record))
            ->modalHeading(__('filament/record-page.merge.heading'))
            ->modalDescription(__('filament/record-page.merge.notice'))
            ->modalSubmitActionLabel(__('filament/record-page.merge.submit'))
            ->schema([
                Select::make('duplicate_id')
                    ->label(__('filament/record-page.merge.search'))
                    ->searchable()
                    ->required()
                    ->getSearchResultsUsing(fn (string $search): array => $this->searchDuplicates($search))
                    ->getOptionLabelUsing(fn (string $value): ?string => Company::query()->find($value)?->name),
                Hidden::make('keep')->default('current'),
            ])
            ->action(function (array $data, ViewRecord $livewire): void {
                try {
                    $kept = $this->merge($data);
                } catch (ValidationException $exception) {
                    $index = array_key_last($livewire->mountedActions);

                    throw ValidationException::withMessages([
                        "mountedActions.{$index}.data.duplicate_id" => $exception->errors()['duplicate_id'],
                    ]);
                }

                Notification::make()->title(__('filament/record-page.merge.merged'))->success()->send();

                $livewire->redirect($livewire::getResource()::getUrl('view', ['record' => $kept]));
            });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function merge(array $data): Company
    {
        $current = $this->company();
        $other = Company::query()->whereKey($data['duplicate_id'])->first();

        if ($other === null || $current->is($other)) {
            throw ValidationException::withMessages(['duplicate_id' => __('filament/record-page.merge.unavailable')]);
        }

        [$kept, $duplicate] = ($data['keep'] ?? 'current') === 'other' ? [$other, $current] : [$current, $other];

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return resolve(MergeCompanies::class)->execute($user, $kept, $duplicate);
    }

    private function company(): Company
    {
        $record = $this->getRecord();
        abort_unless($record instanceof Company, 404);

        return $record;
    }

    /**
     * @return array<string, string>
     */
    private function searchDuplicates(string $search): array
    {
        return Company::query()
            ->whereKeyNot($this->company()->getKey())
            ->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%')
            ->limit(25)
            ->pluck('name', 'id')
            ->all();
    }
}
