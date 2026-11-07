<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\CrmEntity;
use App\Filament\Actions\CopyToClipboardAction;
use App\Filament\Actions\CreateTaskAction;
use App\Filament\Actions\EditTaskAction;
use App\Filament\Components\Infolists\RecordChipEntry;
use App\Filament\Components\Infolists\RecordDetails;
use App\Filament\Components\Infolists\RecordOverview;
use App\Filament\Components\RecordChip;
use App\Filament\Resources\NoteResource\Forms\NoteForm;
use App\Filament\Resources\TaskResource\Forms\TaskForm;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\Entry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Relaticle\EmailIntegration\Filament\Actions\ComposeEmailAction;
use Relaticle\EmailIntegration\Filament\Infolists\CommunicationIntelligenceInfolist;

/**
 * @mixin ViewRecord
 */
trait HasRecordPageLayout
{
    /**
     * @return array<int, Entry>
     */
    abstract public static function nativeDetailEntries(): array;

    /**
     * @return array<int, string>
     */
    abstract public static function highlightNames(): array;

    #[Locked]
    public bool $isEmbedded = false;

    public function mount(int|string $record, bool $isEmbedded = false): void
    {
        $this->isEmbedded = $isEmbedded;

        parent::mount($record);

        if ($isEmbedded) {
            $this->activeRelationManager = null;
        }
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Flex::make([
                RecordChipEntry::make('name')
                    ->hiddenLabel()
                    ->chipSize('lg')
                    ->size(TextSize::Large)
                    ->weight(FontWeight::SemiBold)
                    ->extraAttributes(['class' => 'fi-record-title']),
                $this->recordActions()->grow(false),
            ])
                ->verticallyAlignCenter()
                ->extraAttributes(['class' => 'fi-record-header']),
            $this->quickActions(),
            RecordDetails::section($this->crmRecord(), static::nativeDetailEntries()),
            CommunicationIntelligenceInfolist::section()
                ->contained(false)
                ->extraAttributes(['class' => 'fi-record-rail-section']),
            RecordDetails::recordInfo(),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        if ($this->isEmbedded) {
            return $schema->components([
                View::make('filament.app.record-preview-title')
                    ->viewData(['chip' => RecordChip::forRecord($this->crmRecord(), size: 'lg')]),
                Group::make([$this->getRelationManagersContentComponent()])
                    ->extraAttributes(['class' => 'fi-record-pane']),
            ]);
        }

        return $schema->components([
            Flex::make([
                Group::make([$this->getInfolistContentComponent()])
                    ->grow(false)
                    ->extraAttributes(['class' => 'fi-record-rail']),
                View::make('filament.app.record-rail-resize-handle')
                    ->grow(false),
                Group::make([$this->getRelationManagersContentComponent()])
                    ->extraAttributes(['class' => 'fi-record-pane']),
            ])
                ->from('xl')
                ->extraAttributes([
                    'class' => 'fi-record-layout',
                    'x-data' => 'recordLayout',
                ]),
        ]);
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            $this->isEmbedded ? 'fi-record-embedded-page' : 'fi-record-page',
        ];
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function getHeading(): string|Htmlable
    {
        return $this->isEmbedded ? '' : $this->getRecordTitle();
    }

    public function getHeadingStart(): Htmlable
    {
        $resource = static::getResource();

        return new HtmlString(view('filament.app.record-breadcrumb', [
            'url' => $resource::getUrl('index'),
            'icon' => $resource::getNavigationIcon(),
            'label' => $resource::getTitleCasePluralModelLabel(),
        ])->render());
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabComponent(): Tab
    {
        return Tab::make(__('filament/record-page.overview.label'))
            ->icon('lucide-layout-grid')
            ->extraAttributes(['class' => 'fi-record-overview-tab'])
            ->schema(fn (): array => RecordOverview::components(
                $this->crmRecord(),
                static::nativeDetailEntries(),
                static::highlightNames(),
                $this->overviewTabKeys(),
                $this->overviewCounts(),
            ));
    }

    public function editTaskAction(): EditTaskAction
    {
        return EditTaskAction::make(RecordOverview::EDIT_TASK_ACTION)
            ->record(fn (array $arguments): ?Task => $this->crmRecord()->tasks()->whereKey($arguments['task'] ?? null)->first())
            ->recordTitleAttribute('title')
            ->authorize(fn (mixed $record): bool => $record instanceof Task && Gate::allows('update', $record))
            ->slideOver()
            ->schema(fn (Schema $schema): Schema => TaskForm::get($schema, [$this->crmRecord()->getTable()]))
            ->after(function (): void {
                $this->forceRender();
            });
    }

    public function editNoteAction(): EditAction
    {
        return EditAction::make(RecordOverview::EDIT_NOTE_ACTION)
            ->record(fn (array $arguments): ?Note => $this->crmRecord()->notes()->whereKey($arguments['note'] ?? null)->first())
            ->recordTitleAttribute('title')
            ->authorize(fn (mixed $record): bool => $record instanceof Note && Gate::allows('update', $record))
            ->schema(fn (Schema $schema): Schema => NoteForm::get($schema, [$this->crmRecord()->getTable()]))
            ->after(function (): void {
                $this->forceRender();
            });
    }

    #[On('related-records-changed')]
    #[On('related-record-created')]
    public function refreshRelatedRecordCounts(): void
    {
        $this->forceRender();
    }

    private function quickActions(): Actions
    {
        return Actions::make([
            ComposeEmailAction::make()->size(Size::Small),
            $this->quickCreateAction(CreateAction::make('createNote'), __('filament/record-page.actions.new_note'))
                ->model(Note::class)
                ->icon(CrmEntity::Note->icon())
                ->authorize(fn (): bool => Gate::allows('create', Note::class))
                ->relationship(fn (): MorphToMany => $this->crmRecord()->notes())
                ->schema(fn (Schema $schema): Schema => NoteForm::get($schema, [$this->crmRecord()->getTable()])),
            $this->quickCreateAction(CreateTaskAction::make('createTask'), __('filament/record-page.actions.new_task'))
                ->model(Task::class)
                ->icon(CrmEntity::Task->icon())
                ->authorize(fn (): bool => Gate::allows('create', Task::class))
                ->relationship(fn (): MorphToMany => $this->crmRecord()->tasks())
                ->schema(fn (Schema $schema): Schema => TaskForm::get($schema, [$this->crmRecord()->getTable()])),
        ])
            ->key('quickActions')
            ->extraAttributes(['class' => 'fi-record-rail-quick-actions']);
    }

    private function quickCreateAction(CreateAction $action, string $label): CreateAction
    {
        return $action
            ->label($label)
            ->tooltip($label)
            ->button()
            ->hiddenLabel()
            ->color('gray')
            ->size(Size::Small)
            ->slideOver()
            ->after(fn () => $this->dispatch('related-record-created'));
    }

    /**
     * @return array{activity: ?string, notes: ?string, tasks: ?string, emails: ?string}
     */
    private function overviewTabKeys(): array
    {
        return [
            'activity' => $this->relationManagerKey('ActivityLogRelationManager'),
            'notes' => $this->relationManagerKey('NotesRelationManager'),
            'tasks' => $this->relationManagerKey('TasksRelationManager'),
            'emails' => $this->relationManagerKey('EmailsRelationManager'),
        ];
    }

    /**
     * @return array{notes: ?string, tasks: ?string, emails: ?string}
     */
    private function overviewCounts(): array
    {
        return [
            'notes' => $this->relationManagerBadge('NotesRelationManager'),
            'tasks' => $this->relationManagerBadge('TasksRelationManager'),
            'emails' => $this->relationManagerBadge('EmailsRelationManager'),
        ];
    }

    private function relationManagerKey(string $basename): ?string
    {
        foreach ($this->getRelationManagers() as $key => $manager) {
            if (is_string($manager) && class_basename($manager) === $basename) {
                return (string) $key;
            }
        }

        return null;
    }

    private function relationManagerBadge(string $basename): ?string
    {
        $key = $this->relationManagerKey($basename);
        $manager = $key === null ? null : $this->getRelationManagers()[$key];

        if (! is_string($manager) || ! is_subclass_of($manager, RelationManager::class)) {
            return null;
        }

        return $manager::getBadge($this->crmRecord(), static::class);
    }

    private function crmRecord(): Company|People|Opportunity
    {
        /** @var Company|People|Opportunity */
        return $this->getRecord();
    }

    private function recordActions(): Actions
    {
        return Actions::make([
            EditAction::make()
                ->icon('lucide-square-pen')
                ->color('gray')
                ->size(Size::Small)
                ->after(function (): void {
                    $this->getRecord()->refresh()->load('customFieldValues.customField.options');

                    $this->forceRender();
                }),
            ActionGroup::make([
                ActionGroup::make([
                    CopyToClipboardAction::make('copyPageUrl')
                        ->label(__('filament/record-page.actions.copy_page_url'))
                        ->copies(
                            static::getResource()::getUrl('view', [$this->getRecord()]),
                            __('filament/record-page.notifications.url_copied'),
                        ),
                    CopyToClipboardAction::make('copyRecordId')
                        ->label(__('filament/record-page.actions.copy_record_id'))
                        ->copies((string) $this->getRecord()->getKey(), __('filament/record-page.notifications.id_copied')),
                ])->dropdown(false),
                DeleteAction::make(),
            ])
                ->button()
                ->hiddenLabel()
                ->color('gray')
                ->size(Size::Small)
                ->icon('lucide-ellipsis')
                ->dropdownPlacement('bottom-end'),
        ])
            ->key('recordActions')
            ->extraAttributes(['class' => 'fi-record-rail-actions']);
    }
}
