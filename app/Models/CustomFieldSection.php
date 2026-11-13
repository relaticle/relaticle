<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CustomFieldSectionFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Relaticle\CustomFields\Models\CustomFieldSection as BaseCustomFieldSection;
use Relaticle\CustomFields\Models\Scopes\SortOrderScope;
use Relaticle\CustomFields\Models\Scopes\TenantScope;

#[ScopedBy([TenantScope::class, SortOrderScope::class])]
final class CustomFieldSection extends BaseCustomFieldSection
{
    /** @use HasFactory<CustomFieldSectionFactory> */
    use HasFactory;

    use HasUlids;
}
