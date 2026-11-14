<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Merge\MergeCompanies;
use App\Actions\Merge\MergePeople;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('filament/record-page.actions.merge_record'))
            ->icon('lucide-merge')
            ->authorize(fn (Model $record): bool => ($record instanceof Company || $record instanceof People)
                && Gate::allows('update', $record)
                && Gate::allows('delete', $record))
            ->modalHeading(fn (): Htmlable => $this->heading())
            ->modalWidth(Width::FourExtraLarge)
            ->stickyModalFooter()
            ->modalFooterActionsAlignment(Alignment::End)
            ->modalSubmitAction(fn (Action $action): Action => $this->submitButton($action))
            ->schema([
                MergeRecordPicker::make('duplicate_id'),
                Hidden::make('keep')->default('current'),
            ])
            ->successNotificationTitle(__('filament/record-page.notifications.records_merged'))
            ->action(function (array $data, ViewRecord $livewire): void {
                try {
                    $kept = $this->merge($data);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages([
                        $this->picker()->getStatePath() => $exception->errors()['duplicate_id'] ?? Arr::flatten($exception->errors()),
                    ]);
                }

                $this->success();

                $url = $livewire::getResource()::getUrl('view', ['record' => $kept]);

                $livewire->redirect($url, navigate: FilamentView::hasSpaMode($url));
            });
    }

    private function heading(): Htmlable
    {
        $subject = $this->subject();

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
            ->extraAttributes([
                'class' => 'aria-disabled:pointer-events-auto aria-disabled:cursor-not-allowed aria-disabled:opacity-50',
                'x-on:click' => "if (\$el.getAttribute('aria-disabled') === 'true') { \$event.preventDefault(); \$event.stopImmediatePropagation() }",
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function merge(array $data): Company|People
    {
        $current = $this->subject();
        $id = $data['duplicate_id'] ?? null;
        $other = is_string($id) ? $current::query()->whereKey($id)->first() : null;

        if ($other === null || $current->is($other)) {
            throw ValidationException::withMessages(['duplicate_id' => __('filament/record-page.merge.unavailable')]);
        }

        [$kept, $duplicate] = ($data['keep'] ?? 'current') === 'other' ? [$other, $current] : [$current, $other];

        /** @var User $user */
        $user = Filament::auth()->user();

        return match (true) {
            $kept instanceof People && $duplicate instanceof People => resolve(MergePeople::class)->execute($user, $kept, $duplicate),
            $kept instanceof Company && $duplicate instanceof Company => resolve(MergeCompanies::class)->execute($user, $kept, $duplicate),
            default => abort(404),
        };
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
