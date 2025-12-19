<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Relaticle\CustomFields\Models\CustomField as BaseCustomField;

final class CustomField extends BaseCustomField
{
    use HasUlids;
}
