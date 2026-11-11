<?php

declare(strict_types=1);

namespace App\Filament\Tables\Concerns;

use App\Actions\CustomFields\CreateCustomField;
use App\Enums\CustomFieldType;
use App\Enums\WorkspaceCapability;
use App\Models\CustomField;
use App\Models\User;
use App\Support\CustomFieldDefinitionValidator;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Relaticle\CustomFields\Facades\CustomFieldsType;
use Relaticle\CustomFields\Facades\Entities;

trait CreatesTableAttribute
{
    public const int ATTRIBUTE_NAME_MAX_LENGTH = 50;

    public function createTableAttributeAction(): Action
    {
        return Action::make('createTableAttribute')
            ->label(__('filament/panel.tables.views.create_attribute'))
            ->modalHeading(__('filament/panel.tables.views.create_attribute'))
            ->modalWidth('md')
            ->modalSubmitActionLabel(__('filament/panel.tables.views.create_attribute_submit'))
            ->mountUsing(function (Schema $schema): void {
                abort_unless($this->canCreateTableAttribute(), 403);

                $schema->fill();
            })
            ->schema(fn (): array => $this->tableAttributeFields())
            ->action(fn (array $data, Schema $schema) => $this->createTableAttribute($data, $schema->getStatePath()));
    }

    protected function canCreateTableAttribute(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->hasWorkspaceCapability($user->currentWorkspace?->getKey(), WorkspaceCapability::FieldsManage);
    }

    /**
     * @return list<Select|TextInput|Repeater>
     */
    private function tableAttributeFields(): array
    {
        return [
            TextInput::make('name')
                ->label(__('filament/panel.tables.views.attribute_name'))
                ->required()
                ->maxLength(self::ATTRIBUTE_NAME_MAX_LENGTH)
                ->autofocus(),
            Select::make('type')
                ->label(__('filament/panel.tables.views.attribute_type'))
                ->options($this->tableAttributeTypeOptions())
                ->allowHtml()
                ->native(false)
                ->default(CustomFieldType::TEXT->value)
                ->required()
                ->live(),
            Repeater::make('options')
                ->label(__('filament/panel.tables.views.attribute_options'))
                ->schema([TextInput::make('name')->hiddenLabel()->required()->maxLength(255)])
                ->addActionLabel(__('filament/panel.tables.views.attribute_add_option'))
                ->minItems(1)
                ->maxItems(CustomFieldDefinitionValidator::maxOptions())
                ->visible(fn (Get $get): bool => in_array($get('type'), CreateCustomField::CHOICE_TYPES, true)),
            Select::make('lookup_type')
                ->label(__('filament/panel.tables.views.attribute_lookup'))
                ->options(Entities::getLookupOptions())
                ->native(false)
                ->required()
                ->visible(fn (Get $get): bool => $this->typeRequiresLookup((string) $get('type'))),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function tableAttributeTypeOptions(): array
    {
        $options = [];

        foreach (CustomFieldDefinitionValidator::creatableTypes() as $key) {
            $label = CustomFieldsType::getFieldType($key)->label;
            $icon = CustomFieldType::tryFrom($key)?->icon();

            $options[$key] = $icon === null ? e($label) : $this->typeOptionHtml($icon, $label)->toHtml();
        }

        return $options;
    }

    private function typeOptionHtml(string $icon, string $label): Htmlable
    {
        return new HtmlString('<span class="flex items-center gap-2">'.svg($icon, 'size-4 shrink-0')->toHtml().'<span>'.e($label).'</span></span>');
    }

    private function typeRequiresLookup(string $type): bool
    {
        return in_array($type, CustomFieldDefinitionValidator::creatableTypes(), true)
            && CustomFieldsType::getFieldType($type)?->requiresLookupType === true;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createTableAttribute(array $data, string $statePath): void
    {
        try {
            $field = resolve(CreateCustomField::class)->execute($this->attributeCreator(), [
                ...$this->tableAttributePayload($data),
                'entity_type' => $this->tableEntity()->value,
            ]);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages($this->tableAttributeErrors($exception, $statePath));
        }

        $this->showCreatedTableAttribute($field);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function tableAttributePayload(array $data): array
    {
        $payload = [
            'name' => $data['name'] ?? '',
            'type' => $data['type'] ?? '',
        ];

        if (filled($data['options'] ?? null)) {
            $payload['options'] = array_values($data['options']);
        }

        if (filled($data['lookup_type'] ?? null)) {
            $payload['lookup_type'] = $data['lookup_type'];
        }

        return $payload;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function tableAttributeErrors(ValidationException $exception, string $statePath): array
    {
        $fields = ['name', 'type', 'options', 'lookup_type'];
        $errors = [];

        foreach ($exception->errors() as $key => $messages) {
            $path = in_array(explode('.', $key)[0], $fields, true) ? $key : 'name';

            $errors["{$statePath}.{$path}"] = [...($errors["{$statePath}.{$path}"] ?? []), ...$messages];
        }

        return $errors;
    }

    private function showCreatedTableAttribute(CustomField $field): void
    {
        $this->rebuildTableColumns();

        Notification::make()->success()->title(__('filament/panel.tables.views.attribute_added', ['name' => e($field->name)]))->send();
    }

    // The table is built once per request, before the field exists, so it is built again to carry the new column.
    private function rebuildTableColumns(): void
    {
        $this->table = $this->table($this->makeTable());
        $this->cachedDefaultTableColumnState = null;
        $this->declaredTableColumnNames = null;

        $this->applyTableColumnManager($this->tableColumns, wasReordered: true);
    }

    private function attributeCreator(): User
    {
        /** @var User */
        return auth()->user();
    }
}
