<?php

declare(strict_types=1);

namespace App\Filament\Tables\Concerns;

use App\Actions\CustomFields\CreateCustomField;
use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Enums\WorkspaceCapability;
use App\Models\CustomField;
use App\Models\User;
use App\Support\CustomFieldDefinitionValidator;
use App\Support\CustomFieldSettingsSchema;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Support\Arr;
use Illuminate\Validation\ClosureValidationRule;
use Illuminate\Validation\ValidationException;
use Relaticle\CustomFields\Facades\CustomFieldsType;
use Relaticle\CustomFields\Filament\Management\Forms\Components\TypeField;
use Relaticle\CustomFields\Filament\Management\Schemas\FieldForm;

trait CreatesTableAttribute
{
    abstract protected function attributeEntity(): CrmEntity;

    abstract protected function attributeCreated(CustomField $field): void;

    public function createTableAttributeAction(): Action
    {
        return $this->attributeAction('createTableAttribute', null, $this->attributeCreated(...));
    }

    /**
     * @param  Closure(CustomField): void  $created
     */
    protected function attributeAction(string $name, ?CustomFieldType $type, Closure $created): Action
    {
        return Action::make($name)
            ->label(__('filament/panel.tables.views.create_attribute'))
            ->modalHeading(__('filament/panel.tables.views.create_attribute'))
            ->modalWidth(Width::ScreenLarge)
            ->slideOver()
            ->modalSubmitActionLabel(__('filament/panel.tables.views.create_attribute_submit'))
            ->authorize(fn (): bool => $this->canCreateTableAttribute())
            ->mountUsing(fn (Schema $schema, array $arguments): Schema => $schema->fill([
                'entity_type' => $this->attributeEntity()->value,
                'type' => ($type ?? $this->presetAttributeType($arguments) ?? CustomFieldType::TEXT)->value,
            ]))
            ->schema(fn (): array => $this->tableAttributeFields($type))
            ->action(fn (array $data, Schema $schema) => $created($this->createTableAttribute($type instanceof CustomFieldType ? $this->lockedAttributeData($data, $type) : $data, $schema)));
    }

    protected function canCreateTableAttribute(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->hasWorkspaceCapability($user->currentWorkspace?->getKey(), WorkspaceCapability::FieldsManage);
    }

    // The table's Add column menu opens the form on the type the member picked, and the type stays changeable.
    /**
     * @param  array<string, mixed>  $arguments
     */
    private function presetAttributeType(array $arguments): ?CustomFieldType
    {
        $type = (string) ($arguments['type'] ?? '');

        return in_array($type, CustomFieldDefinitionValidator::creatableTypes(), true) ? CustomFieldType::tryFrom($type) : null;
    }

    /**
     * @return array<int, Component>
     */
    private function tableAttributeFields(?CustomFieldType $type): array
    {
        $fields = FieldForm::schema(withOptionsRelationship: false);

        if ($type instanceof CustomFieldType) {
            $this->lockAttributeType($fields);
        }

        return $fields;
    }

    // A fixed type is the board's grouping field, and the board never groups by an encrypted one.
    /**
     * @param  array<int, mixed>  $components
     */
    private function lockAttributeType(array $components): void
    {
        foreach ($components as $component) {
            if (! $component instanceof Component) {
                continue;
            }

            match (true) {
                $component instanceof TypeField => $component->disabled(),
                $component instanceof Toggle && $component->getName() === 'settings.encrypted' => $component->hidden(),
                default => $this->lockAttributeType((array) $component->getDefaultChildComponents()),
            };
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function lockedAttributeData(array $data, CustomFieldType $type): array
    {
        return [
            ...$data,
            'type' => $type->value,
            'settings' => Arr::except((array) ($data['settings'] ?? []), 'encrypted'),
        ];
    }

    /**
     * @return array<string, array{label: string, icon: ?string}>
     */
    protected function tableAttributeTypes(): array
    {
        $types = [];

        foreach (CustomFieldDefinitionValidator::creatableTypes() as $key) {
            $types[$key] = ['label' => CustomFieldsType::getFieldType($key)->label, 'icon' => CustomFieldType::tryFrom($key)?->icon()];
        }

        return $types;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createTableAttribute(array $data, Schema $schema): CustomField
    {
        try {
            return resolve(CreateCustomField::class)->execute($this->attributeCreator(), [
                ...$this->tableAttributePayload($data),
                'entity_type' => $this->attributeEntity()->value,
            ]);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages($this->tableAttributeErrors($exception, $schema, $data));
        }
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

        $settings = $this->tableAttributeSettings((array) ($data['settings'] ?? []));

        if ($settings !== []) {
            $payload['settings'] = $settings;
        }

        return $payload;
    }

    // The form nests type settings under `additional` and still carries the retired `searchable` toggle.
    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function tableAttributeSettings(array $settings): array
    {
        $flat = [...Arr::except($settings, 'additional'), ...(array) ($settings['additional'] ?? [])];

        return array_filter(Arr::except($flat, CustomFieldSettingsSchema::RETIRED), fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array<int, string>>
     */
    private function tableAttributeErrors(ValidationException $exception, Schema $schema, array $data): array
    {
        $statePath = $schema->getStatePath();
        $rowKeys = array_keys((array) data_get($schema->getRawState(), 'options', []));
        $failed = $exception->validator->failed();
        $errors = [];

        foreach ($exception->errors() as $key => $messages) {
            [$path, $messages] = $this->tableAttributeError($key, $messages, $failed, $data, $rowKeys);

            $errors["{$statePath}.{$path}"] = [...($errors["{$statePath}.{$path}"] ?? []), ...$messages];
        }

        return $errors;
    }

    /**
     * @param  array<int, string>  $messages
     * @param  array<string, array<string, mixed>>  $failed
     * @param  array<string, mixed>  $data
     * @param  list<int|string>  $rowKeys
     * @return array{0: string, 1: array<int, string>}
     */
    private function tableAttributeError(string $key, array $messages, array $failed, array $data, array $rowKeys): array
    {
        $segments = explode('.', $key);

        return match (true) {
            $key === 'name' && isset($failed['name'][ClosureValidationRule::class]) => ['name', [__('filament/panel.tables.views.attribute_name_taken', ['name' => (string) ($data['name'] ?? '')])]],
            $key === 'entity_type' && isset($failed['entity_type'][ClosureValidationRule::class]) => ['name', [__('filament/panel.tables.views.attribute_limit_reached', ['max' => CustomFieldDefinitionValidator::maxFields()])]],
            $segments[0] === 'options' && isset($segments[1]) => [$this->tableAttributeOptionPath($segments, $rowKeys), $messages],
            in_array($segments[0], ['name', 'type', 'options', 'lookup_type'], true) => [$key, $messages],
            $segments[0] === 'settings' && isset($segments[1]) => [$key, $messages],
            default => ['name', $messages],
        };
    }

    /**
     * @param  list<string>  $segments
     * @param  list<int|string>  $rowKeys
     */
    private function tableAttributeOptionPath(array $segments, array $rowKeys): string
    {
        $segments[1] = (string) ($rowKeys[(int) $segments[1]] ?? $segments[1]);

        return implode('.', $segments);
    }

    protected function notifyTableAttributeAdded(CustomField $field): void
    {
        Notification::make()->success()->title(__('filament/panel.tables.views.attribute_added', ['name' => e($field->name)]))->send();
    }

    private function attributeCreator(): User
    {
        /** @var User */
        return auth()->user();
    }
}
