<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Company;
use App\Models\People;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final readonly class RecordMerged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Company|People $kept, public Company|People $duplicate) {}
}
