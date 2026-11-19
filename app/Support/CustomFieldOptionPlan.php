<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\CustomFields\CreateCustomField;
use App\Enums\CrmEntity;
use App\Enums\CustomFields\TaskField;
use App\Enums\OptionColor;
use App\Exceptions\OptionsChangedException;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldValue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class CustomFieldOptionPlan
{
    /**
     * @param  list<array{option: ?CustomFieldOption, name: string, color: ?string, celebrate: ?bool}>  $targets
     * @param  list<array{option: CustomFieldOption, replacement: ?int, clears: bool}>  $removals
     */
    private function __construct(
        public array $targets,
        public array $removals,
        public bool $enablesColors,
        public bool $multiValued,
        private CustomField $field,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{options: list<array{id?: string, name: string, was?: string, color?: string, celebrate?: bool}>, replacements: array<string, string|int|null>, removed: list<string>}
     *
     * @throws ValidationException
     */
    public static function fromToolInput(CustomField $field, array $input): array
    {
        self::assertChoiceField($field);

        $current = self::currentOptions($field);
        $items = array_map(self::toolItem(...), is_array($input['options'] ?? null) ? array_values($input['options']) : []);
        $renamedIds = [];

        foreach ($items as $item) {
            $renamed = self::findByName($current, $item['current']);

            if ($renamed instanceof CustomFieldOption) {
                $renamedIds[] = (string) $renamed->getKey();
            }
        }

        $options = [];
        $claimedIds = [];
        $errors = [];

        foreach ($items as $item) {
            $existing = $item['current'] === ''
                ? self::findByName($current->except($renamedIds), $item['name'])
                : self::findByName($current, $item['current']);

            if ($item['current'] !== '' && ! $existing instanceof CustomFieldOption) {
                $errors[] = __('The field has no option named ":name".', ['name' => $item['current']]);

                continue;
            }

            if (! $existing instanceof CustomFieldOption) {
                $options[] = ['name' => $item['name'], ...self::colorEntry($item['color']), ...self::celebrateEntry($item['celebrate'])];

                continue;
            }

            $id = (string) $existing->getKey();

            if (in_array($id, $claimedIds, true)) {
                $errors[] = __('":name" appears more than once in the list.', ['name' => $existing->name]);

                continue;
            }

            $claimedIds[] = $id;
            $options[] = ['id' => $id, 'name' => $item['name'], 'was' => (string) $existing->name, ...self::colorEntry($item['color']), ...self::celebrateEntry($item['celebrate'])];
        }

        [$replacements, $replacementErrors] = self::toolReplacements($input, $current, $options);
        $errors = [...$errors, ...$replacementErrors];

        if ($errors !== []) {
            throw ValidationException::withMessages(['options' => $errors]);
        }

        return ['options' => $options, 'replacements' => $replacements, 'removed' => array_values($current->except($claimedIds)->keys()->all())];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  Collection<string, CustomFieldOption>  $current
     * @param  list<array{id?: string, name: string}>  $options
     * @return array{array<string, string|int|null>, list<string>}
     */
    private static function toolReplacements(array $input, Collection $current, array $options): array
    {
        $replacements = [];
        $errors = [];
        $requested = is_array($input['replacements'] ?? null) ? $input['replacements'] : [];

        foreach ($requested as $removedName => $keptName) {
            $removed = self::findByName($current, trim((string) $removedName));

            if (! $removed instanceof CustomFieldOption) {
                $errors[] = __('The field has no option named ":name".', ['name' => $removedName]);

                continue;
            }

            if ($keptName === null) {
                $replacements[(string) $removed->getKey()] = null;

                continue;
            }

            $target = self::targetIndexByName($options, self::text($keptName));

            if ($target === null) {
                $errors[] = __('Records on ":name" must move to an option in the new list.', ['name' => $removed->name]);

                continue;
            }

            $replacements[(string) $removed->getKey()] = $options[$target]['id'] ?? $target;
        }

        return [$replacements, $errors];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public static function validated(CustomField $field, array $data, bool $capsClears = true, bool $keepsColors = false): self
    {
        self::assertChoiceField($field);

        $options = self::validatedOptions($data);

        $current = self::currentOptions($field);
        $targets = [];
        $vanished = [];

        foreach ($options as $option) {
            $existing = isset($option['id']) ? $current->get($option['id']) : null;

            if (isset($option['id']) && ! $existing instanceof CustomFieldOption) {
                $vanished[] = __('An option behind ":name" no longer exists on this field. Read its current options and propose again.', ['name' => $option['name']]);

                continue;
            }

            if ($existing instanceof CustomFieldOption && $existing->name !== ($option['was'] ?? null)) {
                throw OptionsChangedException::withMessages(['options' => self::changedSinceProposal($field)]);
            }

            $targets[] = ['option' => $existing, 'name' => $option['name'], 'color' => filled($option['color'] ?? null) ? $option['color'] : null, 'celebrate' => isset($option['celebrate']) ? (bool) $option['celebrate'] : null];
        }

        if ($vanished !== []) {
            throw OptionsChangedException::withMessages(['options' => $vanished]);
        }

        $removed = $current->except(array_column($options, 'id'));

        self::assertRemovalsUnchanged($field, $data, $removed);

        if ($removed->isNotEmpty() && $field->settings->encrypted) {
            throw ValidationException::withMessages([
                'options' => __('Options cannot be removed from an encrypted attribute here. Rename, reorder or add them instead, and remove them in Custom attributes settings.'),
            ]);
        }

        $replacements = is_array($data['replacements'] ?? null) ? $data['replacements'] : [];
        $errors = [];
        $removals = [];
        $moves = [];
        $cleared = [];

        foreach (array_keys($replacements) as $removedId) {
            if (! $removed->has((string) $removedId)) {
                $errors[] = __('A replacement names an option that is not being removed.');
            }
        }

        foreach ($removed as $id => $option) {
            $hasReplacement = array_key_exists($id, $replacements);
            $clears = $hasReplacement ? $replacements[$id] === null : self::onlyDeletedRecordsHold($field, $id);
            $replacement = $hasReplacement && ! $clears ? self::targetIndex($targets, $replacements[$id]) : null;

            if ($hasReplacement && ! $clears && $replacement === null) {
                $errors[] = __('Records on ":name" must move to an option in the new list.', ['name' => $option->name]);
            }

            if ($replacement !== null) {
                $moves[(string) $option->name] = CustomFieldValue::query()->holdingOption($field, $id)->count();
            }

            if (! $hasReplacement && ! $clears && CustomFieldValue::query()->holdingOption($field, $id)->exists()) {
                $errors[] = __('Records still use ":name", so it needs a replacement from the new list.', ['name' => $option->name]);
            }

            $removals[] = self::removalEntry($option, $replacement, $clears);

            if ($clears && $capsClears) {
                $cleared[(string) $option->name] = CustomFieldValue::query()->holdingOption($field, $id)->count();
            }
        }

        $errors = [...$errors, ...array_filter([self::moveCapError($moves, $cleared)])];

        if (self::isTaskStatus($field) && ! self::keepsDone($current, $targets)) {
            $errors[] = __('The task Status option ":name" marks a task complete, so it cannot be renamed or removed.', ['name' => TaskField::doneOption($current)?->name]);
        }

        if ($removals === [] && self::sameList($field, $current, $targets)) {
            $errors[] = __('Nothing to change. The field already has these options in this order.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['options' => $errors]);
        }

        return new self($targets, $removals, ! $keepsColors && self::turnsOnColors($field, $targets), $field->getValueColumn() === 'json_value', $field);
    }

    private static function onlyDeletedRecordsHold(CustomField $field, string $optionId): bool
    {
        return CustomFieldValue::query()->holdingOption($field, $optionId)->exists()
            && ! CustomFieldValue::query()->holdingOptionOnLiveRecord($field, $optionId)->exists();
    }

    /**
     * @return array{option: CustomFieldOption, replacement: ?int, clears: bool}
     */
    private static function removalEntry(CustomFieldOption $option, ?int $replacement, bool $clears): array
    {
        return [
            'option' => $option,
            'replacement' => $replacement,
            'clears' => $clears,
        ];
    }

    /**
     * @param  array<string, int>  $moves
     * @param  array<string, int>  $cleared
     */
    private static function moveCapError(array $moves, array $cleared): ?string
    {
        $moveCap = (int) config('chat.max_option_value_moves', 250);
        $moveTotal = array_sum($moves) + array_sum($cleared);

        if ($moveTotal <= $moveCap) {
            return null;
        }

        $params = ['count' => Number::format($moveTotal), 'max' => Number::format($moveCap)];

        return match (true) {
            $cleared !== [] && count($moves) + count($cleared) === 1 => __('":option" would be cleared on :count records. This assistant clears or moves at most :max records in one approval.', ['option' => array_key_first($cleared), ...$params]),
            $cleared !== [] => __('The options being removed touch :count records in total. This assistant clears or moves at most :max records in one approval.', $params),
            count($moves) === 1 => __('":option" is used by :count records. This assistant moves at most :max records in one approval.', ['option' => array_key_first($moves), ...$params]),
            default => __('The options being removed are used by :count records in total. This assistant moves at most :max records in one approval.', $params),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  Collection<string, CustomFieldOption>  $removed
     *
     * @throws ValidationException
     */
    private static function assertRemovalsUnchanged(CustomField $field, array $data, Collection $removed): void
    {
        $stored = array_map(strval(...), is_array($data['removed'] ?? null) ? $data['removed'] : []);
        $current = $removed->keys()->all();
        sort($stored);
        sort($current);

        if ($stored !== $current) {
            throw OptionsChangedException::withMessages(['options' => self::changedSinceProposal($field)]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{id?: string, name: string, was?: string, color?: string, celebrate?: bool}>
     *
     * @throws ValidationException
     */
    private static function validatedOptions(array $data): array
    {
        return Validator::make($data, [
            'options' => ['required', 'array', 'list', 'max:'.CustomFieldDefinitionValidator::maxOptions()],
            'options.*' => ['array:id,name,was,color,celebrate'],
            'options.*.celebrate' => ['nullable', 'boolean'],
            'options.*.color' => ['nullable', 'string', Rule::enum(OptionColor::class)],
            'options.*.id' => ['sometimes', 'string', 'distinct'],
            'options.*.was' => ['required_with:options.*.id', 'string'],
            'options.*.name' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
            'replacements' => ['sometimes', 'array'],
            'removed' => ['present', 'array'],
            'removed.*' => ['string'],
        ], [
            'options.required' => __('List at least one option.'),
            'options.max' => __('Too many options. At most :max per field.'),
            'options.*.id.distinct' => __('Each existing option can appear only once.'),
            'options.*.color.enum' => __('An option color must be one of the palette colors.'),
        ] + CustomFieldDefinitionValidator::optionNameMessages())->validate()['options'];
    }

    /**
     * @return list<array{label: string, value?: string, old?: string, new?: string}>
     */
    public function displayRows(): array
    {
        $rows = [];

        foreach ($this->targets as $target) {
            if (! $target['option'] instanceof CustomFieldOption) {
                $rows[] = ['label' => __('Added'), 'value' => $target['name']];

                if ($target['color'] !== null) {
                    $rows[] = ['label' => __('Color'), 'value' => $this->colorRow($target['name'], $target['color'])];
                }

                if ($target['celebrate'] === true) {
                    $rows[] = $this->confettiRow($target['name'], true);
                }

                continue;
            }

            if ($target['option']->name !== $target['name']) {
                $rows[] = ['label' => __('Renamed'), 'old' => (string) $target['option']->name, 'new' => $target['name']];
            }

            if ($target['color'] !== null && ($this->enablesColors || self::recolors($target))) {
                $rows[] = ['label' => __('Color'), 'value' => $this->colorRow($target['name'], $target['color'])];
            }

            if (self::changesCelebration($target)) {
                $rows[] = $this->confettiRow($target['name'], (bool) $target['celebrate']);
            }
        }

        foreach ($this->removals as $removal) {
            $rows[] = [
                'label' => __('Removed'),
                'value' => $this->removalText($removal),
            ];
        }

        $rows[] = ['label' => __('New order'), 'value' => implode(', ', array_column($this->targets, 'name'))];

        return $rows;
    }

    /**
     * @param  array{option: CustomFieldOption, replacement: ?int, clears: bool}  $removal
     */
    private function removalText(array $removal): string
    {
        $name = (string) $removal['option']->name;

        if ($removal['replacement'] !== null) {
            return __(':option (records move to :replacement)', ['option' => $name, 'replacement' => $this->targets[$removal['replacement']]['name']]);
        }

        $count = $removal['clears'] ? CustomFieldValue::query()->holdingOptionOnLiveRecord($this->field, (string) $removal['option']->getKey())->count() : 0;

        if ($count === 0) {
            return $name;
        }

        $loss = $this->multiValued
            ? trans_choice(':count record loses this option|:count records lose this option', $count)
            : trans_choice(':count record loses its value|:count records lose their value', $count);

        return "{$name} ({$loss})";
    }

    private static function assertChoiceField(CustomField $field): void
    {
        if (in_array($field->type, CreateCustomField::CHOICE_TYPES, true)) {
            return;
        }

        throw ValidationException::withMessages([
            'options' => __('Field type ":type" has no options. Only select, multi-select, radio, checkbox-list and toggle-buttons fields do.', ['type' => $field->type]),
        ]);
    }

    /**
     * @return Collection<string, CustomFieldOption>
     */
    private static function currentOptions(CustomField $field): Collection
    {
        return CustomFieldOption::query()
            ->where('custom_field_id', $field->getKey())
            ->get()
            ->toBase()
            ->keyBy(fn (CustomFieldOption $option): string => (string) $option->getKey());
    }

    /**
     * @return array{name: string, current: string, color: string, celebrate: ?bool}
     */
    private static function toolItem(mixed $item): array
    {
        if (! is_array($item)) {
            return ['name' => self::text($item), 'current' => '', 'color' => '', 'celebrate' => null];
        }

        return [
            'name' => self::text($item['name'] ?? null),
            'current' => self::text($item['current'] ?? null),
            'color' => self::text($item['color'] ?? null),
            'celebrate' => isset($item['celebrate']) ? filter_var($item['celebrate'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null,
        ];
    }

    /**
     * @return array{celebrate?: bool}
     */
    private static function celebrateEntry(?bool $celebrate): array
    {
        return $celebrate === null ? [] : ['celebrate' => $celebrate];
    }

    /**
     * @return array{color?: string}
     */
    private static function colorEntry(string $color): array
    {
        return $color === '' ? [] : ['color' => $color];
    }

    /**
     * @param  array{option: ?CustomFieldOption, name: string, color: ?string, celebrate: ?bool}  $target
     */
    private static function changesCelebration(array $target): bool
    {
        return $target['option'] instanceof CustomFieldOption
            && $target['celebrate'] !== null
            && $target['celebrate'] !== (bool) $target['option']->celebrates();
    }

    /**
     * @param  array{option: ?CustomFieldOption, name: string, color: ?string, celebrate: ?bool}  $target
     */
    private static function recolors(array $target): bool
    {
        return $target['option'] instanceof CustomFieldOption
            && $target['color'] !== null
            && $target['color'] !== $target['option']->settings->color;
    }

    /**
     * @param  list<array{option: ?CustomFieldOption, name: string, color: ?string, celebrate: ?bool}>  $targets
     */
    private static function turnsOnColors(CustomField $field, array $targets): bool
    {
        return ! $field->settings->enable_option_colors && array_filter(array_column($targets, 'color')) !== [];
    }

    /**
     * @return array{label: string, value: string}
     */
    private function confettiRow(string $name, bool $on): array
    {
        return ['label' => __('Confetti'), 'value' => $name.': '.($on ? __('On') : __('Off'))];
    }

    private function colorRow(string $name, string $color): string
    {
        return $name.': '.(OptionColor::tryFrom($color)?->label() ?? $color);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
    }

    /**
     * @param  Collection<string, CustomFieldOption>  $options
     */
    private static function findByName(Collection $options, string $name): ?CustomFieldOption
    {
        if ($name === '') {
            return null;
        }

        return $options->first(fn (CustomFieldOption $option): bool => $option->name === $name)
            ?? $options->first(fn (CustomFieldOption $option): bool => mb_strtolower((string) $option->name) === mb_strtolower($name));
    }

    /**
     * @param  list<array{id?: string, name: string}>  $options
     */
    private static function targetIndexByName(array $options, string $name): ?int
    {
        if ($name === '') {
            return null;
        }

        foreach ($options as $index => $option) {
            if (mb_strtolower($option['name']) === mb_strtolower($name)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<array{option: ?CustomFieldOption, name: string, color: ?string, celebrate: ?bool}>  $targets
     */
    private static function targetIndex(array $targets, mixed $reference): ?int
    {
        if (is_int($reference)) {
            return isset($targets[$reference]) ? $reference : null;
        }

        if (! is_string($reference)) {
            return null;
        }

        foreach ($targets as $index => $target) {
            if ($target['option'] instanceof CustomFieldOption && (string) $target['option']->getKey() === $reference) {
                return $index;
            }
        }

        return null;
    }

    public static function locksOption(CustomField $field, string $optionId): bool
    {
        return self::isTaskStatus($field) && TaskField::doneOption($field->options)?->getKey() === $optionId;
    }

    private static function isTaskStatus(CustomField $field): bool
    {
        return $field->entity_type === CrmEntity::Task->value && $field->code === TaskField::STATUS->value;
    }

    private static function changedSinceProposal(CustomField $field): string
    {
        return (string) __('The options of ":field" changed after this proposal. Ask for a fresh one.', ['field' => $field->name]);
    }

    /**
     * @param  Collection<string, CustomFieldOption>  $current
     * @param  list<array{option: ?CustomFieldOption, name: string, color: ?string, celebrate: ?bool}>  $targets
     */
    private static function keepsDone(Collection $current, array $targets): bool
    {
        $done = TaskField::doneOption($current);

        if (! $done instanceof CustomFieldOption) {
            return true;
        }

        foreach ($targets as $target) {
            if ($target['option'] instanceof CustomFieldOption && $target['option']->is($done)) {
                return TaskField::isDone($target['name']);
            }
        }

        return false;
    }

    /**
     * @param  Collection<string, CustomFieldOption>  $current
     * @param  list<array{option: ?CustomFieldOption, name: string, color: ?string, celebrate: ?bool}>  $targets
     */
    private static function sameList(CustomField $field, Collection $current, array $targets): bool
    {
        $before = $current
            ->map(fn (CustomFieldOption $option): array => [(string) $option->getKey(), (string) $option->name])
            ->values()
            ->all();

        $after = array_map(
            fn (array $target): array => [
                $target['option'] instanceof CustomFieldOption ? (string) $target['option']->getKey() : null,
                $target['name'],
            ],
            $targets,
        );

        return $before === $after && array_filter($targets, self::recolors(...)) === [] && array_filter($targets, self::changesCelebration(...)) === [] && ! self::turnsOnColors($field, $targets);
    }
}
