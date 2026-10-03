<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Filament\Actions\CreateTaskAction;
use App\Filament\Components\Infolists\RecordChipEntry;
use App\Filament\Resources\NoteResource\Forms\NoteForm;
use App\Filament\Resources\TaskResource\Forms\TaskForm;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\Entry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use Livewire\Attributes\On;
use Relaticle\CustomFields\Facades\CustomFields;
use Relaticle\EmailIntegration\Filament\Actions\ComposeEmailAction;
use Relaticle\EmailIntegration\Filament\Infolists\CommunicationIntelligenceInfolist;

/**
 * @mixin ViewRecord
 */
trait HasRecordPageLayout
{
    private const int VISIBLE_DETAIL_COUNT = 8;

    /**
     * @return array<int, Entry>
     */
    abstract protected function nativeDetailEntries(): array;

    public function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Flex::make([
                RecordChipEntry::make('name')
                    ->hiddenLabel()
                    ->chipSize('lg')
                    ->size(TextSize::Large)
                    ->weight(FontWeight::SemiBold),
                $this->recordActions()->grow(false),
            ])
                ->verticallyAlignCenter(),
            $this->quickActions(),
            $this->detailsSection($schema),
            CommunicationIntelligenceInfolist::section()
                ->contained(false)
                ->extraAttributes(['class' => 'fi-record-rail-section']),
            $this->recordInfoSection(),
        ]);
    }

    public function content(Schema $schema): Schema
    {
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
            'fi-record-page',
        ];
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function getHeading(): string|Htmlable
    {
        return $this->getRecordTitle();
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

    private function crmRecord(): Company|People|Opportunity
    {
        /** @var Company|People|Opportunity */
        return $this->getRecord();
    }

    private function recordActions(): Actions
    {
        return Actions::make([
            EditAction::make()
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->size(Size::Small)
                ->after(function (): void {
                    $this->getRecord()->refresh()->load('customFieldValues.customField.options');

                    $this->forceRender();
                }),
            ActionGroup::make([
                ActionGroup::make([
                    $this->copyToClipboardAction(
                        'copyPageUrl',
                        __('filament/record-page.actions.copy_page_url'),
                        static::getResource()::getUrl('view', [$this->getRecord()]),
                        __('filament/record-page.notifications.url_copied'),
                    ),
                    $this->copyToClipboardAction(
                        'copyRecordId',
                        __('filament/record-page.actions.copy_record_id'),
                        (string) $this->getRecord()->getKey(),
                        __('filament/record-page.notifications.id_copied'),
                    ),
                ])->dropdown(false),
                DeleteAction::make(),
            ])
                ->button()
                ->hiddenLabel()
                ->color('gray')
                ->size(Size::Small)
                ->icon('heroicon-m-ellipsis-horizontal')
                ->dropdownPlacement('bottom-end'),
        ])
            ->key('recordActions')
            ->extraAttributes(['class' => 'fi-record-rail-actions']);
    }

    private function copyToClipboardAction(string $name, string $label, string $value, string $notification): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-clipboard-document')
            ->actionJs(sprintf(
                'navigator.clipboard.writeText(%s).then(() => new FilamentNotification().title(%s).success().send()); close()',
                Js::from($value),
                Js::from($notification),
            ));
    }

    private function detailsSection(Schema $schema): Section
    {
        $customFields = CustomFields::infolist()->withoutSections()->forSchema($schema);

        $customFieldIcons = $customFields->getFields()
            ->mapWithKeys(fn (CustomField $field): array => [$field->getFieldName() => CustomFieldType::tryFrom($field->type)?->icon()]);

        $entries = collect($this->nativeDetailEntries())
            ->concat($customFields->values()->map(fn (Entry $entry): Entry => $entry
                ->beforeLabel(Icon::make($customFieldIcons->get($entry->getName()) ?? Heroicon::OutlinedSquares2x2))))
            ->map(fn (Entry $entry): Entry => $entry
                ->inlineLabel()
                ->columnSpan(['default' => 'full', 'lg' => 'full'])
                ->placeholder(__('filament/record-page.empty')))
            ->values();

        $visible = $entries->take(self::VISIBLE_DETAIL_COUNT)->all();
        $overflow = $entries->skip(self::VISIBLE_DETAIL_COUNT)->all();

        return Section::make(__('filament/record-page.sections.details'))
            ->contained(false)
            ->collapsible()
            ->schema([
                Group::make([
                    ...$visible,
                    ...$this->overflowDetails($overflow),
                ])
                    ->dense()
                    ->extraAttributes(['x-data' => '{ showAllDetails: false }']),
            ])
            ->extraAttributes(['class' => 'fi-record-rail-section']);
    }

    /**
     * @param  array<int, Entry>  $overflow
     * @return array<int, Component>
     */
    private function overflowDetails(array $overflow): array
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

    private function hasMemberCreator(Company|People|Opportunity $record): bool
    {
        return ! $record->isSystemCreated() && $record->creator !== null;
    }

    private function recordInfoSection(): Section
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
                    ->beforeLabel(Icon::make(Heroicon::OutlinedUserCircle))
                    ->visible(fn (Company|People|Opportunity $record): bool => $this->hasMemberCreator($record)),
                TextEntry::make('created_by')
                    ->label(__('filament/record-page.fields.created_by'))
                    ->beforeLabel(Icon::make(Heroicon::OutlinedUserCircle))
                    ->hidden(fn (Company|People|Opportunity $record): bool => $this->hasMemberCreator($record)),
                TextEntry::make('created_at')
                    ->label(__('filament/record-page.fields.created_at'))
                    ->beforeLabel(Icon::make(Heroicon::OutlinedCalendar))
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->label(__('filament/record-page.fields.updated_at'))
                    ->beforeLabel(Icon::make(Heroicon::OutlinedArrowPath))
                    ->dateTime(),
            ])
            ->extraAttributes(['class' => 'fi-record-rail-section']);
    }
}
