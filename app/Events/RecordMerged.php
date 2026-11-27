<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Company;
use App\Models\People;

// Fires mid-transaction, once per original, before the originals are deleted. Listeners must stay synchronous.
final readonly class RecordMerged
{
    public function __construct(public Company|People $merged, public Company|People $original) {}
}
