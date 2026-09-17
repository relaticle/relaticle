<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Actions\Company\UpdateCompany;
use App\Actions\Opportunity\UpdateOpportunity;
use App\Actions\People\UpdatePeople;
use App\Enums\CustomFieldType;
use App\Filament\Components\Forms\RecordSelect;
use App\Filament\Components\Forms\WorkspaceMemberSelect;
use App\Filament\Support\InlineField\InlineCommit;
use App\Filament\Support\InlineField\InlineField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\Entry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconSize;
use Filament\Support\Icons\Heroicon;
use Filament\Support\Livewire\Partials\PartialsComponentHook;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Relaticle\CustomFields\Facades\CustomFields;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Relaticle\CustomFields\Services\Phone\CountryPhoneService;

/**
 * @mixin ViewRecord
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

    #[Locked]
    public ?string $inlineUndoField = null;

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $inlineUndoPayload = null;

    public ?string $inlineEditStatus = null;

    public function startInlineEdit(string $code): void
    {
        $field = $this->resolveInlineField($code);

        if (! $field instanceof InlineField || ! $this->canStartInlineEdit($code)) {
            return;
        }

        $this->eagerLoadInlineRecord();

        $this->inlineEditingField = $code;
        $this->inlineEditStatus = null;
        $this->inlineUndoField = null;
        $this->inlineUndoPayload = null;
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
            $this->addError(
                'inlineEditConflict',
                __('filament/inline-edit.conflict', ['current' => $this->currentInlineDisplayValue($field)]),
            );
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
            $payload = $this->inlinePayloadFromState($field, $schema->getState());
        } catch (ValidationException $exception) {
            $this->forceInlineInfolistRender();

            throw $exception;
        }

        $this->inlineUndoField = $code;
        $this->inlineUndoPayload = $this->currentInlineFormState($field);

        $this->persistInlinePayload($user, $record, $payload);

        $this->inlineEditingField = null;
        $this->inlineEditData = [];
        $this->inlineEditVersion = null;
        $this->inlineEditStatus = __('filament/inline-edit.saved');
        $this->flushInlineEditForm();
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

    public function undoInlineField(): void
    {
        $code = $this->inlineUndoField;
        $payload = $this->inlineUndoPayload;
        $field = $this->resolveInlineField((string) $code);

        if (! $field instanceof InlineField || ! is_array($payload)) {
            return;
        }

        $user = auth()->user();
        $record = $this->getRecord();

        abort_unless($user instanceof User && $user->can('update', $record), 403);

        $this->persistInlinePayload($user, $record, $payload);

        $this->inlineUndoField = null;
        $this->inlineUndoPayload = null;
        $this->inlineEditingField = null;
        $this->inlineEditStatus = null;
        $this->flushInlineEditForm();
        $this->refreshInlineEditedRecord();
    }

    public function isInlineEditing(string $code): bool
    {
        return $this->inlineEditingField === $code;
    }

    public function canStartInlineEdit(string $code): bool
    {
        $user = auth()->user();
        $record = $this->getRecord();

        return $this->resolveInlineField($code) instanceof InlineField
            && $user instanceof User
            && $user->can('update', $record);
    }

    public function canUndoInlineField(string $code): bool
    {
        return $this->inlineUndoField === $code && $this->inlineUndoPayload !== null;
    }

    public function inlineEditForm(Schema $schema): Schema
    {
        return $schema
            ->components($this->inlineEditFormComponents())
            ->statePath('inlineEditData')
            ->model($this->getRecord());
    }

    /**
     * @return list<mixed>
     */
    private function inlineEditFormComponents(): array
    {
        $field = $this->resolveInlineField((string) $this->inlineEditingField);

        if (! $field instanceof InlineField) {
            return [];
        }

        $components = $this->inlineFormFields($field);

        foreach ($components as $component) {
            $this->applyInlineCommitBehavior($component, $field->commit);
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
            return [$this->nativeFormField($field->code)];
        }

        if (! $record instanceof HasCustomFields) {
            return [];
        }

        return array_values(array_filter(
            CustomFields::form()
                ->forModel($record)
                ->only([$field->code])
                ->withoutSections()
                ->values()
                ->all(),
            static fn (mixed $component): bool => $component instanceof Field,
        ));
    }

    private function nativeFormField(string $code): Field
    {
        return match ($code) {
            'name' => TextInput::make('name')
                ->required()
                ->maxLength(255),
            'account_owner_id' => WorkspaceMemberSelect::make('account_owner_id')
                ->relationship('accountOwner', 'name')
                ->label(__('filament/resources/company.fields.account_owner_id.label'))
                ->nullable(),
            'company_id' => RecordSelect::make('company_id')
                ->relationship('company', 'name')
                ->searchable()
                ->preload()
                ->nullable(),
            'contact_id' => RecordSelect::make('contact_id')
                ->relationship('contact', 'name')
                ->searchable()
                ->preload()
                ->nullable(),
            default => abort(404),
        };
    }

    private function applyInlineCommitBehavior(Field $field, InlineCommit $commit): void
    {
        $field->dehydrated(true)->hiddenLabel()->autofocus();

        if ($field instanceof DateTimePicker) {
            $field->closeOnDateSelection();
        }

        if ($field instanceof Select) {
            $field->native(false);
        }

        if ($commit === InlineCommit::OnChange) {
            $field
                ->live()
                ->afterStateUpdated(function (): void {
                    if ($this->inlineEditHydrating) {
                        return;
                    }

                    $this->saveInlineField();
                });
        }
    }

    protected function makeInlineEditable(Entry $entry, string $code): Entry
    {
        $field = $this->resolveInlineField($code);

        if (! $field instanceof InlineField) {
            return $entry;
        }

        $entry->placeholder(__('filament/inline-edit.add', ['field' => $field->label]));
        $entry->extraEntryWrapperAttributes(fn (): array => $this->inlineEntryWrapperAttributes($code));

        $entry->belowContent(
            Html::make(fn (): HtmlString => new HtmlString($this->inlineEditorHtml($code)))
                ->visible(fn (): bool => $this->isInlineEditing($code)),
        );

        $entry->afterContent([
            Icon::make(Heroicon::OutlinedPencilSquare)
                ->size(IconSize::Small)
                ->extraAttributes(['class' => 'fi-inline-edit-pencil'])
                ->visible(fn (): bool => $this->canStartInlineEdit($code) && ! $this->isInlineEditing($code) && ! $this->canUndoInlineField($code)),
            Html::make(fn (): HtmlString => new HtmlString($this->inlineFeedbackHtml($code)))
                ->visible(fn (): bool => $this->canUndoInlineField($code)),
        ]);

        return $entry;
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

        if (! $this->canStartInlineEdit($code)) {
            return $attributes;
        }

        $attributes['class'] = 'fi-inline-editable';

        if ($this->isInlineEditing($code)) {
            return $attributes;
        }

        $start = "startInlineEdit('{$code}')";

        $attributes['role'] = 'button';
        $attributes['tabindex'] = '0';
        $attributes['wire:click'] = $start;
        $attributes['wire:keydown.enter'] = $start;
        $attributes['wire:keydown.space.prevent'] = $start;
        $attributes['x-on:click.capture'] = 'if ($event.target.closest?.(\'a[href]\')) $event.stopImmediatePropagation()';

        return $attributes;
    }

    /**
     * @return list<Entry>
     */
    protected function inlineEditableCustomFieldEntries(): array
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
    protected function inlineCustomFieldCodes(): array
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
            'error' => $this->inlineEditorError(),
            'saveOnEnterOrBlur' => $field?->commit === InlineCommit::OnEnterOrBlur,
            'saveOnChange' => $field?->commit === InlineCommit::OnChange,
            'saveOnConfirm' => $field?->commit === InlineCommit::OnConfirm,
        ])->render();
    }

    private function inlineEditorError(): ?string
    {
        if (! $this->getErrorBag()->has('inlineEditConflict')) {
            return null;
        }

        $message = $this->getErrorBag()->first('inlineEditConflict');

        return is_string($message) && $message !== '' ? $message : null;
    }

    private function inlineFeedbackHtml(string $code): string
    {
        return view('filament.infolists.components.inline-field-feedback', [
            'status' => $this->inlineEditStatus,
            'showUndo' => $this->canUndoInlineField($code),
        ])->render();
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
        $record = $this->getRecord();

        if (! $field->isCustom()) {
            return [$field->code => $record->getAttribute($field->code)];
        }

        if (! $record instanceof HasCustomFields) {
            return [];
        }

        $this->eagerLoadInlineRecord();

        $customField = CustomField::query()
            ->forEntity($record::class)
            ->where('code', $field->code)
            ->first();

        if (! $customField instanceof CustomField) {
            return ['custom_fields' => [$field->code => null]];
        }

        $value = $record->getCustomFieldValue($customField);

        if ($value instanceof Collection) {
            $value = $value->all();
        }

        return ['custom_fields' => [$field->code => $this->hydrateInlineCustomFieldValue($customField, $value)]];
    }

    private function normalizeInlineEditData(InlineField $field): void
    {
        if (! $field->isCustom()) {
            return;
        }

        $path = 'custom_fields.'.$field->code;
        $value = data_get($this->inlineEditData, $path);
        $customField = CustomField::query()
            ->forEntity($this->getRecord()::class)
            ->where('code', $field->code)
            ->first();

        if (! $customField instanceof CustomField) {
            return;
        }

        $type = CustomFieldType::tryFrom($customField->type);

        if (! in_array($type, [CustomFieldType::LINK, CustomFieldType::EMAIL], true)) {
            return;
        }

        data_set($this->inlineEditData, $path, $this->normalizeInlineStringList($value));
    }

    /**
     * @return list<string>
     */
    private function normalizeInlineStringList(mixed $value): array
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $item = trim($item);

            if ($item === '') {
                continue;
            }

            $items[] = $item;
        }

        return array_values($items);
    }

    private function hydrateInlineCustomFieldValue(CustomField $customField, mixed $value): mixed
    {
        $type = CustomFieldType::tryFrom($customField->type);

        if (in_array($type, [CustomFieldType::LINK, CustomFieldType::EMAIL], true)) {
            return $this->normalizeInlineStringList($value);
        }

        if ($customField->type !== CustomFieldType::PHONE->value) {
            return $value;
        }

        $service = resolve(CountryPhoneService::class);
        $defaultCountry = $service->detectCountryFromLocale();

        if (! is_array($value) || $value === []) {
            return [['country' => $defaultCountry, 'number' => '']];
        }

        return array_values(array_map(
            fn (mixed $entry): array => is_string($entry)
                ? $service->parseE164($entry, $defaultCountry)
                : (is_array($entry) ? $entry : ['country' => $defaultCountry, 'number' => '']),
            $value,
        ));
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function inlinePayloadFromState(InlineField $field, array $state): array
    {
        if (! $field->isCustom()) {
            return Arr::only($state, [$field->code]);
        }

        $value = data_get($state, 'custom_fields.'.$field->code);

        return ['custom_fields' => [$field->code => $value]];
    }

    private function currentInlineDisplayValue(InlineField $field): string
    {
        $empty = __('filament/inline-edit.add', ['field' => $field->label]);
        $record = $this->getRecord();

        if (! $field->isCustom()) {
            return $this->currentNativeDisplayValue($field, $record, $empty);
        }

        if (! $record instanceof HasCustomFields) {
            return $empty;
        }

        $this->eagerLoadInlineRecord();

        $customField = CustomField::query()
            ->forEntity($record::class)
            ->where('code', $field->code)
            ->with('options')
            ->first();

        if (! $customField instanceof CustomField) {
            return $empty;
        }

        $value = $record->getCustomFieldValue($customField);

        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (blank($value)) {
            return $empty;
        }

        $option = $customField->options->firstWhere('id', $value)
            ?? $customField->options->firstWhere('name', $value);

        if (is_object($option) && filled($option->name ?? null)) {
            return (string) $option->name;
        }

        if (is_array($value)) {
            $labels = array_map(static function (mixed $item) use ($customField): string {
                $option = $customField->options->firstWhere('id', $item)
                    ?? $customField->options->firstWhere('name', $item);

                if (is_object($option) && filled($option->name ?? null)) {
                    return (string) $option->name;
                }

                return is_scalar($item) ? (string) $item : '';
            }, $value);

            $labels = array_values(array_filter($labels, static fn (string $label): bool => $label !== ''));

            return $labels === [] ? $empty : implode(', ', $labels);
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return $empty;
    }

    private function currentNativeDisplayValue(InlineField $field, Model $record, string $empty): string
    {
        if ($field->code === 'account_owner_id' && $record instanceof Company) {
            $record->loadMissing('accountOwner');

            return filled($record->accountOwner?->name)
                ? (string) $record->accountOwner->name
                : $empty;
        }

        if ($field->code === 'company_id' && ($record instanceof People || $record instanceof Opportunity)) {
            $record->loadMissing('company');

            return filled($record->company?->name)
                ? (string) $record->company->name
                : $empty;
        }

        if ($field->code === 'contact_id' && $record instanceof Opportunity) {
            $record->loadMissing('contact');

            return filled($record->contact?->name)
                ? (string) $record->contact->name
                : $empty;
        }

        $value = $record->getAttribute($field->code);

        return filled($value) && is_scalar($value) ? (string) $value : $empty;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persistInlinePayload(User $user, Model $record, array $payload): void
    {
        match (true) {
            $record instanceof Opportunity => resolve(UpdateOpportunity::class)->execute($user, $record, $payload),
            $record instanceof Company => resolve(UpdateCompany::class)->execute($user, $record, $payload),
            $record instanceof People => resolve(UpdatePeople::class)->execute($user, $record, $payload),
            default => abort(404),
        };
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
        $this->getRecord()->refresh();
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
