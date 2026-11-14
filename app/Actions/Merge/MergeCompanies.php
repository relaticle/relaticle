<?php

declare(strict_types=1);

namespace App\Actions\Merge;

use App\Actions\Company\UpdateCompany;
use App\Data\MergedValues;
use App\Enums\MediaCollection;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class MergeCompanies
{
    public function __construct(
        private MergeRecords $mergeRecords,
        private UpdateCompany $updateCompany,
    ) {}

    public function execute(User $user, Company $kept, Company $duplicate): Company
    {
        return $this->mergeRecords->execute(
            $user,
            $kept,
            $duplicate,
            $this->moveCompanyLinks(...),
            fn (Company $kept, MergedValues $values): Company => $this->updateCompany->execute($user, $kept, [
                ...$values->attributes,
                'custom_fields' => $values->customFields,
            ]),
        );
    }

    private function moveCompanyLinks(Company $kept, Company $duplicate): void
    {
        foreach (['people', 'opportunities'] as $table) {
            DB::table($table)->where('company_id', $duplicate->getKey())->update(['company_id' => $kept->getKey()]);
        }

        $logos = fn (Company $company): Builder => DB::table('media')
            ->where('model_type', $company->getMorphClass())
            ->where('model_id', $company->getKey())
            ->where('collection_name', MediaCollection::Logo->value);

        if (! $logos($kept)->exists()) {
            $logos($duplicate)->update(['model_id' => $kept->getKey()]);
        }
    }
}
