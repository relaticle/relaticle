<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Relaticle\CustomFields\Models\CustomFieldOption as BaseCustomFieldOption;
use Relaticle\CustomFields\Models\Scopes\SortOrderScope;
use Relaticle\CustomFields\Models\Scopes\TenantScope;

#[ScopedBy([TenantScope::class, SortOrderScope::class])]
final class CustomFieldOption extends BaseCustomFieldOption
{
    use HasUlids;
}
