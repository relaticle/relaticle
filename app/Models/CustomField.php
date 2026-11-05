<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CustomFieldFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Relaticle\CustomFields\Enums\FieldCapability;
use Relaticle\CustomFields\Enums\OptionCategory;
use Relaticle\CustomFields\Models\CustomField as BaseCustomField;
use Relaticle\CustomFields\Models\Scopes\SortOrderScope;
use Relaticle\CustomFields\Models\Scopes\TenantScope;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property string $tenant_id
 */
#[ScopedBy([TenantScope::class, SortOrderScope::class])]
final class CustomField extends BaseCustomField
{
    use HasUlids;
    use LogsActivity;

    /**
     * Whether saving an arbitrary value to this field should promote that value
     * into the field's user-managed option list. True for tags-input; false for
     * email/phone/link, which also accept arbitrary values but own no option list.
     */
    public function promotesValuesToOptions(): bool
    {
        return $this->typeData->supports(FieldCapability::AcceptsArbitraryValues) && ! $this->typeData->supports(FieldCapability::WithoutUserOptions);
    }

    public function isRequired(): bool
    {
        return (bool) collect($this->validation_rules)->get('required', false);
    }

    /** @return list<string> */
    public function optionIdsIn(OptionCategory ...$categories): array
    {
        return array_values($this->optionsInCategory(...$categories)
            ->map(static fn (Model $option): string => (string) $option->getKey())
            ->all());
    }

    // A field with no completed option is not categorised yet, so it answers none and the
    // caller keeps showing every record rather than hide a set it cannot describe.
    /** @return list<string> */
    public function terminalOptionIds(): array
    {
        if ($this->optionsInCategory(OptionCategory::Completed)->isEmpty()) {
            $this->warnUncategorised();

            return [];
        }

        return $this->optionIdsIn(...array_filter(
            OptionCategory::cases(),
            static fn (OptionCategory $category): bool => $category->isTerminal(),
        ));
    }

    // Saving a record writes a row for every field, so a row alone does not mean a value.
    public function hasValues(): bool
    {
        return CustomFieldValue::query()->holdingAValue($this)->exists();
    }

    /** @return CustomFieldFactory */
    protected static function newFactory(): Factory
    {
        return CustomFieldFactory::new();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'active', 'settings'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('crm')
            ->setDescriptionForEvent(fn (string $eventName): string => $eventName);
    }

    private function warnUncategorised(): void
    {
        $seen = self::class.":uncategorised:{$this->tenant_id}:{$this->code}";

        if (app()->bound($seen)) {
            return;
        }

        app()->instance($seen, true);

        Log::warning('Custom field has no completed option, so finished records cannot be told apart.', [
            'workspace_id' => $this->tenant_id,
            'field_code' => $this->code,
        ]);
    }
}
