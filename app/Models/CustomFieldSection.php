<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Relaticle\CustomFields\Models\CustomFieldSection as BaseCustomFieldSection;
use Relaticle\CustomFields\Models\Scopes\SortOrderScope;
use Relaticle\CustomFields\Models\Scopes\TenantScope;
use Relaticle\CustomFields\Observers\CustomFieldSectionObserver;

#[ScopedBy([TenantScope::class, SortOrderScope::class])]
#[ObservedBy(CustomFieldSectionObserver::class)]
final class CustomFieldSection extends BaseCustomFieldSection
{
    use HasUlids;
}
