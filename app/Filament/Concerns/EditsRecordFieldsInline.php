<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\CustomFieldType;
use App\Enums\InlineCommit;
use App\Filament\CustomFields\RichEditorComponent;
use App\Filament\Support\InlineField\FieldState;
use App\Filament\Support\InlineField\InlineField;
use App\Filament\Support\InlineField\NativeFormField;
use App\Filament\Support\InlineField\RecordWriter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\Entry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconSize;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Support\Livewire\Partials\PartialsComponentHook;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Relaticle\CustomFields\Facades\CustomFields;
use Relaticle\CustomFields\Filament\Integration\Components\Forms\MultiValueInput\MultiValueInputComponent;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;

/**
 * @mixin ViewRecord
 *
 * @method \Filament\Actions\ActionGroup recordOverflowActions()
 * @method Html recordDetailsOverflowToggle()
 */
trait EditsRecordFieldsInline
{
    #[Locked]
    public ?string $inlineEditingField = null;

    /** @var array<string, mixed> */
    public array $inlineEditData = [];

    #[Locked]
    public ?string $inlineEditVersion = null;

    #[Locked]
    public bool $inlineEditHydrating = false;

    public function startInlineEdit(string $code): void
    {
        $field = $this->resolveInlineField($code);

        if (! $field instanceof InlineField || ! $this->canStartInlineEdit($code)) {
            return;
        }

        if ($this->inlineEditingField === $code) {
            return;
        }

        if (! $this->closeOpenInlineField()) {
            return;
        }

        if ($field->opensInModal()) {
            $this->mountAction('editRichField', ['code' => $code]);

            return;
        }

        $this->eagerLoadInlineRecord();

        $this->inlineEditingField = $code;
        $this->inlineEditVersion = $this->recordVersion();
        $this->flushInlineEditForm();
        $this->resetErrorBag();

        $this->inlineEditHydrating = true;

        try {
            $this->getSchema('inlineEditForm')?->fill($this->currentInlineFormState($field));
        } finally {
            $this->inlineEditHydrating = false;
        }

        $this->forceInlineInfolistRender();
    }

    public function rendering(): void
    {
        $record = $this->getRecord();

        if (! $record->exists) {
            return;
        }

        $this->eagerLoadInlineRecord();
    }

    public function saveInlineField(): void
    {
        $user = auth()->user();
        $record = $this->getRecord();

        abort_unless($user instanceof User && $user->can('update', $record), 403);

        $code = $this->inlineEditingField;
        $field = $this->resolveInlineField((string) $code);

        if (! $field instanceof InlineField) {
            return;
        }

        $this->resetErrorBag();

        if ($this->isInlineEditStale()) {
            $draft = $this->inlineEditData;
            $conflict = __('filament/inline-edit.conflict', ['current' => $this->currentInlineDisplayValue($field)]);
            $conflict = is_string($conflict) ? $conflict : '';
            $this->addError('inlineEditConflict', $conflict);
            $this->notifyInlineFailure($conflict);
            $this->inlineEditVersion = $this->recordVersion();
            $this->refreshInlineEditedRecord();
            $this->inlineEditData = $draft;

            return;
        }

        $schema = $this->getSchema('inlineEditForm');

        if ($schema === null) {
            return;
        }

        $this->normalizeInlineEditData($field);

        try {
            $payload = $this->inlineFieldState()->payloadFromState($field, $schema->getState());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                foreach ($messages as $message) {
                    if (is_string($key) && is_string($message)) {
                        $this->addError($key, $message);
                    }
                }
            }

            $this->notifyInlineFailure($this->firstValidationMessage($exception));
            $this->forceInlineInfolistRender();

            throw $exception;
        }

        $this->persistInlinePayload($user, $record, $payload);

        if ($field->keepsEditorOpen()) {
            $this->getRecord()->refresh();
            $this->eagerLoadInlineRecord();
            $this->inlineEditVersion = $this->recordVersion();

            return;
        }

        $this->inlineEditingField = null;
        $this->inlineEditData = [];
        $this->inlineEditVersion = null;
        $this->flushInlineEditForm();
        $this->refreshInlineEditedRecord();
    }

    public function toggleInlineBoolean(string $code): void
    {
        $user = auth()->user();
        $record = $this->getRecord();

        abort_unless($user instanceof User && $user->can('update', $record), 403);

        $field = $this->resolveInlineField($code);

        if (! $field instanceof InlineField || ! $field->isBoolean()) {
            return;
        }

        $this->eagerLoadInlineRecord();

        $next = ! $this->currentInlineBoolean($field);

        $this->inlineEditingField = null;
        $this->inlineEditData = [];
        $this->inlineEditVersion = null;
        $this->persistInlinePayload($user, $record, $this->inlineFieldState()->booleanPayload($field, $next));
        $this->refreshInlineEditedRecord();
    }

    public function cancelInlineEdit(): void
    {
        $this->inlineEditingField = null;
        $this->inlineEditData = [];
        $this->inlineEditVersion = null;
        $this->inlineEditHydrating = false;
        $this->flushInlineEditForm();
        $this->resetErrorBag();
        $this->forceInlineInfolistRender();
    }

    private function closeOpenInlineField(): bool
    {
        $current = $this->inlineEditingField;

        if ($current === null) {
            return true;
        }

        $this->saveInlineField();

        if ($this->inlineEditingField === null) {
            return true;
        }

        $field = $this->resolveInlineField($current);

        if ($field instanceof InlineField && $field->keepsEditorOpen()) {
            $this->cancelInlineEdit();

            return true;
        }

        return false;
    }

    public function isInlineEditing(string $code): bool
    {
        return $this->inlineEditingField === $code;
    }

    public function canStartInlineEdit(string $code): bool
    {
        $user = auth()->user();
        $record = $this->getRecord();

        $field = $this->resolveInlineField($code);

        return $field instanceof InlineField
            && ! $field->isBoolean()
            && $user instanceof User
            && $user->can('update', $record);
    }

    public function editRichFieldAction(): Action
    {
        return Action::make('editRichField')
            ->authorize(function (): bool {
                $user = auth()->user();

                return $user instanceof User && $user->can('update', $this->getRecord());
            })
            ->slideOver()
            ->modalWidth(Width::FiveExtraLarge)
            ->modalHeading(function (array $arguments): string {
                $field = $this->resolveModalRichField($arguments);

                return $field instanceof InlineField ? $field->label : '';
            })
            ->fillForm(function (array $arguments): array {
                $field = $this->resolveModalRichField($arguments);

                return $field instanceof InlineField ? $this->currentInlineFormState($field) : [];
            })
            ->schema(function (array $arguments): array {
                $field = $this->resolveModalRichField($arguments);

                if (! $field instanceof InlineField) {
                    return [];
                }

                $components = $this->inlineFormFields($field);

                foreach ($components as $component) {
                    $component->dehydrated(true)->hiddenLabel();

                    if ($component instanceof RichEditorComponent) {
                        $component->asDocument()->autofocus();
                    }
                }

                return $components;
            })
            ->action(function (array $data, array $arguments): void {
                $user = auth()->user();
                $record = $this->getRecord();

                abort_unless($user instanceof User && $user->can('update', $record), 403);

                $field = $this->resolveModalRichField($arguments);

                if (! $field instanceof InlineField) {
                    return;
                }

                $this->persistInlinePayload($user, $record, $this->inlineFieldState()->payloadFromState($field, $data));
                $this->inlineEditingField = null;
                $this->inlineEditData = [];
                $this->refreshInlineEditedRecord();
            });
    }

    public function inlineEditForm(Schema $schema): Schema
    {
        return $schema
            ->components($this->inlineEditFormComponents())
            ->statePath('inlineEditData')
            ->model($this->getRecord());
    }

    /**
     * @param  list<Entry>  $nativeEntries
     * @param  list<Entry>  $readonlyEntries
     */
    protected function recordDetailsInfolist(Schema $schema, string $sectionKey, array $nativeEntries, array $readonlyEntries = []): Schema
    {
        $columns = $this->stackedInlineColumns();
        $span = $this->stackedInlineColumnSpan();

        foreach ($readonlyEntries as $entry) {
            $entry->columnSpan($span);
        }

        return $schema
            ->inlineLabel()
            ->columns($columns)
            ->schema([
                Section::make()
                    ->key($sectionKey)
                    ->inlineLabel()
                    ->headerActions([
                        $this->recordOverflowActions(),
                    ])
                    ->schema([
                        ...$nativeEntries,
                        ...$this->inlineEditableCustomFieldEntries(),
                        CustomFields::infolist()
                            ->forSchema($schema)
                            ->except($this->inlineCustomFieldCodes())
                            ->build()
                            ->columns($columns)
                            ->columnSpan($span),
                        ...$readonlyEntries,
                    ])
                    ->footer($this->recordDetailsOverflowToggle())
                    ->columns($columns)
                    ->columnSpan($span)
                    ->compact(),
            ]);
    }

    /**
     * @return list<Field>
     */
    private function inlineEditFormComponents(): array
    {
        $field = $this->resolveInlineField((string) $this->inlineEditingField);

        if (! $field instanceof InlineField) {
            return [];
        }

        $components = $this->inlineFormFields($field);

        foreach ($components as $component) {
            $this->applyInlineCommitBehavior($component, $field);
        }

        return $components;
    }

    /**
     * @return list<Field>
     */
    private function inlineFormFields(InlineField $field): array
    {
        $record = $this->getRecord();

        if (! $field->isCustom()) {
            return [NativeFormField::make($field->code)];
        }

        if (! $record instanceof HasCustomFields) {
            return [];
        }

        $components = array_values(array_filter(
            CustomFields::form()
                ->forModel($record)
                ->only([$field->code])
                ->withoutSections()
                ->values()
                ->all(),
            static fn (mixed $component): bool => $component instanceof Field,
        ));

        return array_map(
            fn (Field $component): Field => $this->inlineChoiceEditorField($field, $component),
            $components,
        );
    }

    private function inlineChoiceEditorField(InlineField $field, Field $component): Field
    {
        if ($field->type === CustomFieldType::RADIO && $component instanceof Radio) {
            return Select::make($component->getName())
                ->options($component->getOptions())
                ->extraAttributes(['class' => 'fi-inline-radio-select']);
        }

        if ($field->type === CustomFieldType::CHECKBOX_LIST && $component instanceof CheckboxList) {
            return Select::make($component->getName())
                ->options($component->getOptions())
                ->multiple();
        }

        return $component;
    }

    private function applyInlineCommitBehavior(Field $component, InlineField $field): void
    {
        $component->dehydrated(true)->hiddenLabel();

        if (
            ! $component instanceof ColorPicker
            && ! $component instanceof CheckboxList
            && ! $component instanceof Radio
            && ! $component instanceof MultiValueInputComponent
        ) {
            $component->autofocus();
        }

        if ($component instanceof Textarea) {
            $component->rows(2)->autosize();
        }

        if ($component instanceof DateTimePicker) {
            $component->closeOnDateSelection();
        }

        if ($component instanceof Select) {
            $component->native(false);
        }

        if ($field->commit !== InlineCommit::OnChange) {
            return;
        }

        $component
            ->live()
            ->afterStateUpdated(function (): void {
                if ($this->inlineEditHydrating) {
                    return;
                }

                $this->saveInlineField();
            });
    }

    /**
     * @return array<string, int>
     */
    private function stackedInlineColumns(): array
    {
        return [
            'default' => 1,
            'sm' => 1,
            'md' => 1,
            'lg' => 1,
            'xl' => 1,
            '2xl' => 1,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function stackedInlineColumnSpan(): array
    {
        return [
            'default' => 'full',
            'sm' => 'full',
            'md' => 'full',
            'lg' => 'full',
            'xl' => 'full',
            '2xl' => 'full',
        ];
    }

    protected function makeInlineEditable(Entry $entry, string $code): Entry
    {
        $field = $this->resolveInlineField($code);

        if (! $field instanceof InlineField) {
            return $entry;
        }

        $entry->columnSpan($this->stackedInlineColumnSpan());

        if (! $field->isBoolean()) {
            $entry->placeholder($this->inlineEmptyPlaceholder($field));

            if ($entry instanceof ViewEntry) {
                $vendorView = $entry->getView();

                if ($vendorView !== 'filament.infolists.components.inline-custom-field-entry') {
                    $entry->view('filament.infolists.components.inline-custom-field-entry', [
                        'vendorView' => $vendorView,
                    ]);
                }
            }
        }

        $entry->extraEntryWrapperAttributes(fn (): array => $this->inlineEntryWrapperAttributes($code));

        if ($field->isBoolean()) {
            $entry->afterContent([
                Html::make(fn (): HtmlString => new HtmlString($this->inlineBooleanSwitchHtml($code))),
            ]);

            return $entry;
        }

        if ($field->opensInModal()) {
            $entry->afterContent([
                $this->inlineEditPencil(fn (): bool => $this->canStartInlineEdit($code)),
            ]);

            return $entry;
        }

        $entry->belowContent(
            Html::make(fn (): HtmlString => new HtmlString($this->inlineEditorHtml($code)))
                ->visible(fn (): bool => $this->isInlineEditing($code)),
        );

        $entry->afterContent([
            $this->inlineEditPencil(fn (): bool => $this->canStartInlineEdit($code) && ! $this->isInlineEditing($code)),
        ]);

        return $entry;
    }

    private function inlineEditPencil(Closure $visible): Icon
    {
        return Icon::make(Heroicon::OutlinedPencilSquare)
            ->size(IconSize::Small)
            ->extraAttributes(['class' => 'fi-inline-edit-pencil'])
            ->visible($visible);
    }

    /**
     * @return array<string, string>
     */
    private function inlineEntryWrapperAttributes(string $code): array
    {
        $attributes = [
            'data-inline-field' => $code,
            'data-inline-editing' => $this->isInlineEditing($code) ? 'true' : 'false',
        ];

        $field = $this->resolveInlineField($code);

        if ($field instanceof InlineField && $field->isBoolean()) {
            $attributes['class'] = 'fi-inline-boolean';

            return $attributes;
        }

        if (! $this->canStartInlineEdit($code)) {
            return $attributes;
        }

        $attributes['class'] = 'fi-inline-editable';

        if ($this->isInlineEditing($code)) {
            return $attributes;
        }

        $start = 'startInlineEdit('.Js::from($code).')';

        $attributes['role'] = 'button';
        $attributes['tabindex'] = '0';
        $attributes['wire:click'] = $start;
        $attributes['wire:keydown.enter'] = $start;
        $attributes['wire:keydown.space.prevent'] = $start;
        $attributes['x-on:click.capture'] = 'if ($event.composedPath().some((n) => n.tagName === \'A\' && n.hasAttribute(\'href\'))) $event.stopImmediatePropagation()';

        return $attributes;
    }

    /**
     * @return list<Entry>
     */
    private function inlineEditableCustomFieldEntries(): array
    {
        $record = $this->getRecord();
        $entries = [];

        foreach (InlineField::for($record::class) as $field) {
            if (! $field->isCustom() || ! $record instanceof HasCustomFields) {
                continue;
            }

            $entry = CustomFields::infolist()
                ->forModel($record)
                ->only([$field->code])
                ->withoutSections()
                ->values()
                ->first();

            if (! $entry instanceof Entry) {
                continue;
            }

            $entries[] = $this->makeInlineEditable($entry, $field->code);
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function inlineCustomFieldCodes(): array
    {
        return array_values(array_map(
            static fn (InlineField $field): string => $field->code,
            array_filter(
                InlineField::for($this->getRecord()::class),
                static fn (InlineField $field): bool => $field->isCustom(),
            ),
        ));
    }

    private function inlineEditorHtml(string $code): string
    {
        if (! $this->isInlineEditing($code)) {
            return '';
        }

        $field = $this->resolveInlineField($code);

        return view('filament.infolists.components.inline-field-editor', [
            'formHtml' => $this->getSchema('inlineEditForm')?->toHtml() ?? '',
            'saveOnEnterOrBlur' => $field?->commit === InlineCommit::OnEnterOrBlur,
            'saveOnChange' => $field?->commit === InlineCommit::OnChange,
            'invalid' => $this->inlineEditorHasError(),
        ])->render();
    }

    private function inlineEditorHasError(): bool
    {
        if ($this->getErrorBag()->has('inlineEditConflict')) {
            return true;
        }

        foreach ($this->getErrorBag()->keys() as $key) {
            if (str_starts_with((string) $key, 'inlineEditData')) {
                return true;
            }
        }

        return false;
    }

    private function notifyInlineFailure(string $message): void
    {
        if ($message === '') {
            return;
        }

        Notification::make()
            ->title($message)
            ->danger()
            ->send();
    }

    private function firstValidationMessage(ValidationException $exception): string
    {
        $message = Arr::first(Arr::flatten($exception->errors()));

        return is_string($message) ? $message : '';
    }

    private function inlineBooleanSwitchHtml(string $code): string
    {
        $field = $this->resolveInlineField($code);

        if (! $field instanceof InlineField) {
            return '';
        }

        $user = auth()->user();
        $record = $this->getRecord();

        return view('filament.infolists.components.inline-boolean-switch', [
            'code' => $code,
            'label' => $field->label,
            'on' => $this->currentInlineBoolean($field),
            'disabled' => ! $user instanceof User || ! $user->can('update', $record),
        ])->render();
    }

    private function currentInlineBoolean(InlineField $field): bool
    {
        return filter_var(data_get($this->currentInlineFormState($field), $field->valuePath()), FILTER_VALIDATE_BOOLEAN);
    }

    private function resolveInlineField(string $code): ?InlineField
    {
        if ($code === '') {
            return null;
        }

        return InlineField::tryFor($this->getRecord()::class, $code);
    }

    /**
     * @return array<string, mixed>
     */
    private function currentInlineFormState(InlineField $field): array
    {
        if ($field->isCustom()) {
            $this->eagerLoadInlineRecord();
        }

        return $this->inlineFieldState()->formState($field);
    }

    private function normalizeInlineEditData(InlineField $field): void
    {
        $normalized = $this->inlineFieldState()->normalizedLinkOrEmailList(
            $field,
            data_get($this->inlineEditData, $field->valuePath()),
        );

        if ($normalized === null) {
            return;
        }

        data_set($this->inlineEditData, $field->valuePath(), $normalized);
    }

    private function inlineEmptyPlaceholder(InlineField $field): string
    {
        return __('filament/inline-edit.set', ['field' => mb_strtolower($field->label)]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function resolveModalRichField(array $arguments): ?InlineField
    {
        $field = $this->resolveInlineField((string) ($arguments['code'] ?? ''));

        if (! $field instanceof InlineField || ! $field->opensInModal()) {
            return null;
        }

        return $field;
    }

    private function currentInlineDisplayValue(InlineField $field): string
    {
        if ($field->isCustom()) {
            $this->eagerLoadInlineRecord();
        }

        return $this->inlineFieldState()->displayValue($field, $this->inlineEmptyPlaceholder($field));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persistInlinePayload(User $user, Model $record, array $payload): void
    {
        resolve(RecordWriter::class)->execute($user, $record, $payload);
    }

    private function inlineFieldState(): FieldState
    {
        return new FieldState($this->getRecord());
    }

    private function recordVersion(): string
    {
        $updatedAt = $this->getRecord()->getAttribute('updated_at');

        if (! $updatedAt instanceof CarbonImmutable) {
            return '';
        }

        return $updatedAt->toJSON();
    }

    private function isInlineEditStale(): bool
    {
        $this->getRecord()->refresh();
        $this->eagerLoadInlineRecord();

        return $this->inlineEditVersion !== $this->recordVersion();
    }

    private function flushInlineEditForm(): void
    {
        unset($this->cachedSchemas['inlineEditForm']);
    }

    /**
     * @return list<string>
     */
    protected function inlineEditEagerLoads(): array
    {
        return ['customFieldValues.customField.options'];
    }

    protected function refreshInlineEditedRecord(): void
    {
        $record = $this->getRecord();
        $record->refresh();

        foreach ($this->inlineEditEagerLoads() as $path) {
            $record->unsetRelation(explode('.', $path)[0]);
        }

        $this->eagerLoadInlineRecord();
        $this->forceInlineInfolistRender();
    }

    private function eagerLoadInlineRecord(): void
    {
        $record = $this->getRecord();

        if (! $record->exists) {
            return;
        }

        foreach ($this->inlineEditEagerLoads() as $path) {
            $segments = explode('.', $path);
            $root = array_shift($segments);

            if (! $record->relationLoaded($root)) {
                $record->load($path);

                continue;
            }

            if ($segments === []) {
                continue;
            }

            $related = $record->getRelation($root);
            $nested = implode('.', $segments);

            if ($related instanceof EloquentCollection) {
                $related->loadMissing($nested);

                continue;
            }

            if ($related instanceof Model) {
                $related->loadMissing($nested);
            }
        }
    }

    private function forceInlineInfolistRender(): void
    {
        resolve(PartialsComponentHook::class)->forceRender($this);
    }
}
