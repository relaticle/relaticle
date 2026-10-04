<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Relaticle\CustomFields\Models\CustomFieldLink as BaseCustomFieldLink;
use Relaticle\CustomFields\Models\Scopes\TenantScope;

/**
 * @property string $tenant_id
 */
#[ScopedBy([TenantScope::class])]
final class CustomFieldLink extends BaseCustomFieldLink
{
    use HasUlids;
}
