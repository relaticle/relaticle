<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Relaticle\CustomFields\Models\CustomFieldSection as BaseCustomFieldSection;

final class CustomFieldSection extends BaseCustomFieldSection
{
    use HasUlids;
}
