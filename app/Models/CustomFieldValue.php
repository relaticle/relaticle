<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Relaticle\CustomFields\Models\CustomFieldValue as BaseCustomFieldValue;

final class CustomFieldValue extends BaseCustomFieldValue
{
    use HasUlids;
}
