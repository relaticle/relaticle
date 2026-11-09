<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class InactiveRecipientsRejected
{
    use Dispatchable;

    /**
     * @param  list<string>  $addresses
     */
    public function __construct(public array $addresses) {}
}
