<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\CustomFields\CreateCustomField;
use App\Enums\CrmEntity;
use App\Enums\CustomFields\TaskField;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldValue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class CustomFieldOptionPlan
{
    // CompleteTask, GetCrmSummary, DigestService and MyTasksService find the completed status by this name.
    private const string DONE_STATUS = 'Done';

    /**
     * @param  list<array{option: ?CustomFieldOption, name: string}>  $targets
     * @param  list<array{option: CustomFieldOption, replacement: ?int}>  $removals
     */
    private function __construct(
        private array $targets,
        private array $removals,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{options: list<array{id?: string, name: string}>, replacements: array<string, string|int>, removed: list<string>}
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
                $options[] = ['name' => $item['name']];

                continue;
            }

            $id = (string) $existing->getKey();

            if (in_array($id, $claimedIds, true)) {
                $errors[] = __('":name" appears more than once in the list.', ['name' => $existing->name]);

                continue;
            }

            $claimedIds[] = $id;
            $options[] = ['id' => $id, 'name' => $item['name']];
        }

        $replacements = [];
        $requested = is_array($input['replacements'] ?? null) ? $input['replacements'] : [];

        foreach ($requested as $removedName => $keptName) {
            $removed = self::findByName($current, trim((string) $removedName));
            $target = self::targetIndexByName($options, self::text($keptName));

            if (! $removed instanceof CustomFieldOption) {
                $errors[] = __('The field has no option named ":name".', ['name' => $removedName]);

                continue;
            }

            if ($target === null) {
                $errors[] = __('Records on ":name" must move to an option in the new list.', ['name' => $removed->name]);

                continue;
            }

            $replacements[(string) $removed->getKey()] = $options[$target]['id'] ?? $target;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['options' => $errors]);
        }

        return ['options' => $options, 'replacements' => $replacements, 'removed' => array_values($current->except($claimedIds)->keys()->all())];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public static function validated(CustomField $field, array $data): self
    {
        self::assertChoiceField($field);

        /** @var list<array{id?: string, name: string}> $options */
        $options = Validator::make($data, [
            'options' => ['required', 'array', 'list', 'max:'.CustomFieldDefinitionValidator::maxOptions()],
            'options.*' => ['array:id,name'],
            'options.*.id' => ['sometimes', 'string', 'distinct'],
            'options.*.name' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
            'replacements' => ['sometimes', 'array'],
            'removed' => ['present', 'array'],
            'removed.*' => ['string'],
        ], [
            'options.required' => __('List at least one option.'),
            'options.max' => __('Too many options. At most :max per field.'),
            'options.*.id.distinct' => __('Each existing option can appear only once.'),
        ] + CustomFieldDefinitionValidator::optionNameMessages())->validate()['options'];

        $current = self::currentOptions($field);
        $targets = [];
        $vanished = [];

        foreach ($options as $option) {
            $existing = isset($option['id']) ? $current->get($option['id']) : null;

            if (isset($option['id']) && ! $existing instanceof CustomFieldOption) {
                $vanished[] = __('An option behind ":name" no longer exists on this field. Read its current options and propose again.', ['name' => $option['name']]);

                continue;
            }

            $targets[] = ['option' => $existing, 'name' => $option['name']];
        }

        if ($vanished !== []) {
            throw ValidationException::withMessages(['options' => $vanished]);
        }

        $keptIds = [];

        foreach ($targets as $target) {
            if ($target['option'] instanceof CustomFieldOption) {
                $keptIds[] = (string) $target['option']->getKey();
            }
        }

        $removed = $current->except($keptIds);

        $storedRemoved = array_map(strval(...), is_array($data['removed'] ?? null) ? $data['removed'] : []);
        $currentRemoved = $removed->keys()->all();
        sort($storedRemoved);
        sort($currentRemoved);

        if ($storedRemoved !== $currentRemoved) {
            throw ValidationException::withMessages([
                'options' => __('The options of ":field" changed after this proposal. Ask for a fresh one.', ['field' => $field->name]),
            ]);
        }

        if ($removed->isNotEmpty() && $field->settings->encrypted) {
            throw ValidationException::withMessages([
                'options' => __('Options cannot be removed from an encrypted field here. Rename, reorder or add them instead, and remove them in Custom Fields settings.'),
            ]);
        }

        $replacements = is_array($data['replacements'] ?? null) ? $data['replacements'] : [];
        $errors = [];
        $removals = [];
        $moves = [];

        foreach (array_keys($replacements) as $removedId) {
            if (! $removed->has((string) $removedId)) {
                $errors[] = __('A replacement names an option that is not being removed.');
            }
        }

        foreach ($removed as $id => $option) {
            $hasReplacement = array_key_exists($id, $replacements);
            $replacement = $hasReplacement ? self::targetIndex($targets, $replacements[$id]) : null;

            if ($hasReplacement && $replacement === null) {
                $errors[] = __('Records on ":name" must move to an option in the new list.', ['name' => $option->name]);
            }

            if ($replacement !== null) {
                $moves[(string) $option->name] = CustomFieldValue::query()->holdingOption($field, $id)->count();
            }

            if (! $hasReplacement && CustomFieldValue::query()->holdingOption($field, $id)->exists()) {
                $errors[] = __('Records still use ":name", so it needs a replacement from the new list.', ['name' => $option->name]);
            }

            $removals[] = ['option' => $option, 'replacement' => $replacement];
        }

        $moveCap = self::maxValueMoves();
        $moveTotal = array_sum($moves);

        if ($moveTotal > $moveCap) {
            $errors[] = count($moves) === 1
                ? __('":option" is used by :count records. This assistant moves at most :max records in one approval.', ['option' => array_key_first($moves), 'count' => number_format($moveTotal), 'max' => number_format($moveCap)])
                : __('The options being removed are used by :count records in total. This assistant moves at most :max records in one approval.', ['count' => number_format($moveTotal), 'max' => number_format($moveCap)]);
        }

        if (self::isTaskStatus($field) && ! self::keepsDone($current, $targets)) {
            $errors[] = __('The task Status option "Done" marks a task complete, so it cannot be renamed or removed.');
        }

        if ($removals === [] && self::sameList($current, $targets)) {
            $errors[] = __('Nothing to change. The field already has these options in this order.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['options' => $errors]);
        }

        return new self($targets, $removals);
    }

    /**
     * @return list<array{option: ?CustomFieldOption, name: string}>
     */
    public function targets(): array
    {
        return $this->targets;
    }

    /**
     * @return list<array{option: CustomFieldOption, replacement: ?int}>
     */
    public function removals(): array
    {
        return $this->removals;
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

                continue;
            }

            if ($target['option']->name !== $target['name']) {
                $rows[] = ['label' => __('Renamed'), 'old' => (string) $target['option']->name, 'new' => $target['name']];
            }
        }

        foreach ($this->removals as $removal) {
            $rows[] = [
                'label' => __('Removed'),
                'value' => $removal['replacement'] === null
                    ? (string) $removal['option']->name
                    : __(':option (records move to :replacement)', [
                        'option' => $removal['option']->name,
                        'replacement' => $this->targets[$removal['replacement']]['name'],
                    ]),
            ];
        }

        $rows[] = ['label' => __('New order'), 'value' => implode(', ', array_column($this->targets, 'name'))];

        return $rows;
    }

    private static function maxValueMoves(): int
    {
        return (int) config('chat.max_option_value_moves', 1000);
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
            ->with('customField')
            ->where('custom_field_id', $field->getKey())
            ->get()
            ->toBase()
            ->keyBy(fn (CustomFieldOption $option): string => (string) $option->getKey());
    }

    /**
     * @return array{name: string, current: string}
     */
    private static function toolItem(mixed $item): array
    {
        if (! is_array($item)) {
            return ['name' => self::text($item), 'current' => ''];
        }

        return [
            'name' => self::text($item['name'] ?? null),
            'current' => self::text($item['current'] ?? null),
        ];
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
     * @param  list<array{option: ?CustomFieldOption, name: string}>  $targets
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

    private static function isTaskStatus(CustomField $field): bool
    {
        return $field->entity_type === CrmEntity::Task->value && $field->code === TaskField::STATUS->value;
    }

    /**
     * @param  Collection<string, CustomFieldOption>  $current
     * @param  list<array{option: ?CustomFieldOption, name: string}>  $targets
     */
    private static function keepsDone(Collection $current, array $targets): bool
    {
        $done = $current->first(fn (CustomFieldOption $option): bool => $option->name === self::DONE_STATUS);

        if (! $done instanceof CustomFieldOption) {
            return true;
        }

        foreach ($targets as $target) {
            if ($target['option'] instanceof CustomFieldOption && $target['option']->is($done)) {
                return $target['name'] === self::DONE_STATUS;
            }
        }

        return false;
    }

    /**
     * @param  Collection<string, CustomFieldOption>  $current
     * @param  list<array{option: ?CustomFieldOption, name: string}>  $targets
     */
    private static function sameList(Collection $current, array $targets): bool
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

        return $before === $after;
    }
}
