<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Relaticle\CustomFields\Models\CustomFieldRelationship as BaseCustomFieldRelationship;
use Relaticle\CustomFields\Models\Scopes\TenantScope;
use Relaticle\CustomFields\Observers\CustomFieldRelationshipObserver;

/**
 * @property string $tenant_id
 */
#[ScopedBy([TenantScope::class])]
#[ObservedBy(CustomFieldRelationshipObserver::class)]
final class CustomFieldRelationship extends BaseCustomFieldRelationship
{
    use HasUlids;
}
