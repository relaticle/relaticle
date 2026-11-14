<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CrmEntity;
use App\Enums\CustomFields\NoteField;
use App\Enums\CustomFields\TaskField;
use App\Enums\CustomFieldType;
use Database\Factories\CustomFieldFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\CustomFields\Models\CustomField as BaseCustomField;
use Relaticle\CustomFields\Models\Scopes\SortOrderScope;
use Relaticle\CustomFields\Models\Scopes\TenantScope;
use Relaticle\CustomFields\Observers\CustomFieldObserver;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property string $tenant_id
 */
#[ScopedBy([TenantScope::class, SortOrderScope::class])]
#[ObservedBy(CustomFieldObserver::class)]
final class CustomField extends BaseCustomField
{
    use HasUlids;
    use LogsActivity;

    // The borderless canvas fills the form's slack, which only reads as a document where
    // the editor is the form's main field. Elsewhere it would swallow the fields around it.
    private const string FALLBACK_ICON = 'lucide-layout-grid';

    /** @var array<string, string> */
    private const array DOCUMENT_FIELDS = [
        CrmEntity::Note->value => NoteField::BODY->value,
        CrmEntity::Task->value => TaskField::DESCRIPTION->value,
    ];

    /**
     * Whether saving an arbitrary value to this field should promote that value
     * into the field's user-managed option list. True for tags-input; false for
     * email/phone/link, which also accept arbitrary values but own no option list.
     */
    public function promotesValuesToOptions(): bool
    {
        return $this->typeData->acceptsArbitraryValues && ! $this->typeData->withoutUserOptions;
    }

    // Saving a record writes a row for every field, so a row alone does not mean a value.
    public function hasValues(): bool
    {
        return CustomFieldValue::query()->holdingAValue($this)->exists();
    }

    public function typeIcon(): string
    {
        return CustomFieldType::tryFrom($this->type)?->icon() ?? self::FALLBACK_ICON;
    }

    public function isDocument(): bool
    {
        return (self::DOCUMENT_FIELDS[$this->entity_type] ?? null) === $this->code;
    }

    public function holdsChecklists(): bool
    {
        return $this->entity_type === CrmEntity::Task->value
            && $this->code === TaskField::DESCRIPTION->value;
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
}
