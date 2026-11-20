<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Onboarding\ScheduleAssistantArrivalTurn;
use App\Models\People;
use App\Observers\Concerns\TagsFirstCrmData;

final readonly class PeopleObserver
{
    use TagsFirstCrmData;

    public function __construct(private ScheduleAssistantArrivalTurn $scheduleAssistantArrival) {}

    public function created(People $people): void
    {
        $this->tagFirstCrmDataIfNeeded($people);
        $this->scheduleAssistantArrival->execute($people);
    }
}
