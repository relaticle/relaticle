<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Merge\MergeRecords;
use App\Enums\CrmEntity;
use App\Filament\Components\Forms\MergeRecordPicker;
use App\Models\Company;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Models\Contracts\HasAvatar;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Component;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentView;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

final class MergeRecordAction extends Action
{
    public static function getDefaultName(): string
    {
        return 'mergeRecord';
    }

    public static function canMerge(Model $record): bool
    {
        return ($record instanceof Company || $record instanceof People)
            && ! $record->trashed()
            && Gate::allows('create', $record::class)
            && Gate::allows('delete', $record);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('filament/record-page.actions.merge_record'))
            ->icon('lucide-merge')
            ->authorize(fn (Model $record): bool => self::canMerge($record))
            ->modalHeading(fn (): Htmlable => $this->heading())
            ->modalWidth(Width::FourExtraLarge)
            ->stickyModalFooter()
            ->modalFooterActionsAlignment(Alignment::End)
            ->modalSubmitAction(fn (Action $action): Action => $this->submitButton($action))
            ->schema([
                MergeRecordPicker::make('picked_id'),
                Hidden::make('keep_picked')->default(false),
            ])
            ->successNotificationTitle(__('filament/record-page.notifications.records_merged'))
            ->action(function (array $data, ViewRecord $livewire): void {
                try {
                    $merged = $this->merge($data);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages([
                        $this->picker()->getStatePath() => Arr::flatten($exception->errors()),
                    ]);
                }

                $this->success();

                $url = $livewire::getResource()::getUrl('view', ['record' => $merged]);

                $livewire->redirect($url, navigate: FilamentView::hasSpaMode($url));
            });
    }

    private function heading(): Htmlable
    {
        $subject = $this->subject();

        // Filament escapes a View handed to modalHeading, so the heading is rendered to markup here.
        return new HtmlString(view('filament.app.merge-record.heading', [
            'name' => $subject->name,
            'logo' => $subject instanceof HasAvatar ? $subject->getFilamentAvatarUrl() : null,
            'icon' => CrmEntity::tryFromModel($subject)?->icon(),
        ])->render());
    }

    private function submitButton(Action $action): Action
    {
        return $action
            ->disabled($this->picker()->isBlocked())
            ->icon('lucide-merge')
            ->label(__('filament/record-page.merge.submit'))
            // Filament's capture-phase disabled-button listener blocks the click once pointer events return.
            ->extraAttributes([
                'class' => 'aria-disabled:pointer-events-auto aria-disabled:cursor-not-allowed aria-disabled:opacity-50',
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function merge(array $data): Company|People
    {
        $current = $this->subject();
        $pickedId = $data['picked_id'] ?? null;
        $picked = is_string($pickedId) ? $current::query()->whereKey($pickedId)->first() : null;

        if ($picked === null) {
            throw ValidationException::withMessages(['picked_id' => __('filament/record-page.merge.unavailable')]);
        }

        [$priority, $other] = ($data['keep_picked'] ?? false) === true ? [$picked, $current] : [$current, $picked];

        /** @var User $user */
        $user = Filament::auth()->user();

        return resolve(MergeRecords::class)->execute($user, $priority, $other);
    }

    private function subject(): Company|People
    {
        $record = $this->getRecord();
        abort_unless($record instanceof Company || $record instanceof People, 404);

        return $record;
    }

    private function picker(): MergeRecordPicker
    {
        $livewire = $this->getLivewire();
        abort_unless($livewire instanceof ViewRecord, 404);

        $picker = $livewire->getSchema((string) $livewire->getMountedActionSchemaName())
            ?->getComponent(fn (Component $component): bool => $component instanceof MergeRecordPicker);
        abort_unless($picker instanceof MergeRecordPicker, 404);

        return $picker;
    }
}
