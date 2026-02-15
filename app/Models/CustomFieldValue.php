<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Relaticle\CustomFields\Models\CustomFieldValue as BaseCustomFieldValue;
use Relaticle\CustomFields\Models\Scopes\TenantScope;

#[ScopedBy([TenantScope::class])]
final class CustomFieldValue extends BaseCustomFieldValue
{
    use HasUlids;
}
