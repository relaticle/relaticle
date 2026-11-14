<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Company;
use App\Models\People;

final readonly class RecordMerged
{
    public function __construct(public Company|People $kept, public Company|People $duplicate) {}
}
