<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Opportunity;
use App\Observers\Concerns\ForgetsFavorites;
use App\Observers\Concerns\TagsFirstCrmData;

final readonly class OpportunityObserver
{
    use ForgetsFavorites;
    use TagsFirstCrmData;

    public function created(Opportunity $opportunity): void
    {
        $this->tagFirstCrmDataIfNeeded($opportunity);
    }
}
