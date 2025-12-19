<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Relaticle\CustomFields\Models\CustomFieldOption as BaseCustomFieldOption;

final class CustomFieldOption extends BaseCustomFieldOption
{
    use HasUlids;
}
