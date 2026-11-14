<?php

declare(strict_types=1);

namespace App\Actions\Merge;

use App\Actions\People\UpdatePeople;
use App\Data\MergedValues;
use App\Models\People;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class MergePeople
{
    public function __construct(
        private MergeRecords $mergeRecords,
        private UpdatePeople $updatePeople,
    ) {}

    public function execute(User $user, People $kept, People $duplicate): People
    {
        return $this->mergeRecords->execute(
            $user,
            $kept,
            $duplicate,
            $this->moveOpportunities(...),
            fn (People $kept, MergedValues $values): People => $this->updatePeople->execute($user, $kept, [
                ...$values->attributes,
                'custom_fields' => $values->customFields,
            ]),
        );
    }

    private function moveOpportunities(People $kept, People $duplicate): void
    {
        DB::table('opportunities')
            ->where('contact_id', $duplicate->getKey())
            ->update(['contact_id' => $kept->getKey()]);
    }
}
