<?php

declare(strict_types=1);

namespace App\Actions\Merge;

use App\Actions\Company\UpdateCompany;
use App\Data\MergedValues;
use App\Enums\CreationSource;
use App\Enums\MediaCollection;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class MergeCompanies
{
    public function __construct(
        private CombineRecordValues $combine,
        private MoveRecordLinks $moveLinks,
        private UpdateCompany $updateCompany,
    ) {}

    public function execute(User $user, Company $kept, Company $duplicate): Company
    {
        abort_unless($user->can('update', $kept) && $user->can('delete', $duplicate), 403);
        abort_if($kept->is($duplicate) || $kept->workspace_id !== $duplicate->workspace_id, 404);

        return DB::transaction(function () use ($user, $kept, $duplicate): Company {
            [$kept, $duplicate] = $this->lock($kept, $duplicate);

            $values = $this->combine->execute($kept, $duplicate);

            $this->assertWithinLimits($kept, $values);

            if ($kept->creation_source === CreationSource::SAMPLE && $duplicate->creation_source !== CreationSource::SAMPLE) {
                $kept->update(['creation_source' => $duplicate->creation_source]);
            }

            $this->moveLinks->execute($kept, $duplicate);
            $this->moveCompanyLinks($kept, $duplicate);

            $duplicate->delete();

            return $this->updateCompany->execute($user, $kept, [
                ...$values->attributes,
                'custom_fields' => $values->customFields,
            ]);
        });
    }

    /**
     * @return array{0: Company, 1: Company}
     */
    private function lock(Company $kept, Company $duplicate): array
    {
        $locked = Company::query()
            ->whereKey([$kept->getKey(), $duplicate->getKey()])
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (Company $company): string => (string) $company->getKey());

        abort_unless($locked->has((string) $kept->getKey()) && $locked->has((string) $duplicate->getKey()), 404);

        return [$locked[$kept->getKey()], $locked[$duplicate->getKey()]];
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

    private function assertWithinLimits(Company $kept, MergedValues $values): void
    {
        $code = array_key_first($values->overLimit);

        if ($code === null) {
            return;
        }

        $label = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $kept->workspace_id)
            ->where('entity_type', $kept->getMorphClass())
            ->where('code', $code)
            ->value('name');

        throw ValidationException::withMessages([
            'duplicate_id' => __('filament/record-page.merge.over_limit', ['field' => $label, 'max' => $values->overLimit[$code]]),
        ]);
    }
}
