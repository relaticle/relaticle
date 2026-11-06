<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\Opportunity;
use Carbon\CarbonImmutable;
use Relaticle\EmailIntegration\Models\Email;

final class OpportunityWindows
{
    /** @var array<string, array{0: CarbonImmutable|null, 1: CarbonImmutable|null}> */
    private array $windows = [];

    /**
     * The span an opportunity shows its company's and point of contact's mail: from 30 days before it opened, to 30 days after a past close date.
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    public function of(Opportunity $opportunity): array
    {
        return $this->windows[$opportunity->getKey().'@'.$opportunity->updated_at?->toIso8601String()] ??= $this->read($opportunity);
    }

    /** @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null} */
    private function read(Opportunity $opportunity): array
    {
        $closeDate = $opportunity->closeDate();

        return [
            $opportunity->created_at?->subDays(Email::OPPORTUNITY_WINDOW_DAYS),
            $closeDate instanceof CarbonImmutable && $closeDate->isPast() ? $closeDate->addDays(Email::OPPORTUNITY_WINDOW_DAYS)->endOfDay() : null,
        ];
    }
}
