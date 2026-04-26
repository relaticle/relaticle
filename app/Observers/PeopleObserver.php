<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\People;
use App\Observers\Concerns\TagsFirstCrmData;

final readonly class PeopleObserver
{
    use TagsFirstCrmData;

    public function created(People $people): void
    {
        $this->tagFirstCrmDataIfNeeded($people);
    }

    public function saved(People $people): void
    {
        $people->invalidateAiSummary();
    }
}
