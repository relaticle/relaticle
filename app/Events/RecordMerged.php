<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Company;
use App\Models\People;

// Fires mid-transaction, before the duplicate is deleted. Listeners must stay synchronous.
final readonly class RecordMerged
{
    public function __construct(public Company|People $kept, public Company|People $duplicate) {}
}
