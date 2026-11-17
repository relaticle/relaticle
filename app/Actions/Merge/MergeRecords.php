<?php

declare(strict_types=1);

namespace App\Actions\Merge;

use App\Actions\Company\UpdateCompany;
use App\Actions\People\UpdatePeople;
use App\Data\MergedValues;
use App\Enums\CreationSource;
use App\Enums\MediaCollection;
use App\Events\RecordMerged;
use App\Models\Company;
use App\Models\People;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class MergeRecords
{
    public function __construct(
        private CombineRecordValues $combine,
        private MoveRecordLinks $moveLinks,
        private MoveRecordHistory $moveHistory,
        private UpdateCompany $updateCompany,
        private UpdatePeople $updatePeople,
    ) {}

    public function execute(User $user, Company|People $kept, Company|People $duplicate): Company|People
    {
        abort_unless($user->can('update', $kept) && $user->can('delete', $duplicate), 403);

        if ($kept::class !== $duplicate::class || $kept->is($duplicate) || $kept->workspace_id !== $duplicate->workspace_id) {
            throw $this->unavailable();
        }

        return DB::transaction(function () use ($user, $kept, $duplicate): Company|People {
            [$kept, $duplicate] = $this->lock($kept, $duplicate);

            $mergedValues = $this->combine->execute($kept, $duplicate);

            $this->assertWithinLimits($mergedValues);

            if ($kept->creation_source === CreationSource::SAMPLE && $duplicate->creation_source !== CreationSource::SAMPLE) {
                $kept->update(['creation_source' => $duplicate->creation_source]);
            }

            $this->moveLinks->execute($kept, $duplicate, $mergedValues);
            $this->moveEntityLinks($kept, $duplicate);
            $this->moveHistory->execute($user, $kept, $duplicate);

            event(new RecordMerged($kept, $duplicate));

            $duplicate->delete();

            return $this->saveMergedValues($user, $kept, $mergedValues);
        });
    }

    private function moveEntityLinks(Company|People $kept, Company|People $duplicate): void
    {
        match (true) {
            $kept instanceof Company => $this->moveCompanyLinks($kept, (string) $duplicate->getKey()),
            $kept instanceof People => DB::table('opportunities')
                ->where('contact_id', $duplicate->getKey())
                ->update(['contact_id' => $kept->getKey()]),
        };
    }

    private function moveCompanyLinks(Company $kept, string $duplicateId): void
    {
        DB::table('people')->where('company_id', $duplicateId)->update(['company_id' => $kept->getKey()]);
        DB::table('opportunities')->where('company_id', $duplicateId)->update(['company_id' => $kept->getKey()]);
        $this->moveLogoIfKeptHasNone($kept, $duplicateId);
    }

    private function moveLogoIfKeptHasNone(Company $kept, string $duplicateId): void
    {
        $logoOf = fn (string $recordId): Builder => DB::table('media')
            ->where('model_type', $kept->getMorphClass())
            ->where('model_id', $recordId)
            ->where('collection_name', MediaCollection::Logo->value);

        if (! $logoOf((string) $kept->getKey())->exists()) {
            $logoOf($duplicateId)->update(['model_id' => $kept->getKey()]);
        }
    }

    private function saveMergedValues(User $user, Company|People $kept, MergedValues $mergedValues): Company|People
    {
        $data = [...$mergedValues->attributes, 'custom_fields' => $mergedValues->customFields];

        return match ($kept::class) {
            Company::class => $this->updateCompany->execute($user, $kept, $data),
            People::class => $this->updatePeople->execute($user, $kept, $data),
        };
    }

    /**
     * @template TRecord of Company|People
     *
     * @param  TRecord  $kept
     * @param  TRecord  $duplicate
     * @return array{0: TRecord, 1: TRecord}
     */
    private function lock(Company|People $kept, Company|People $duplicate): array
    {
        /** @var Collection<string, TRecord> $locked */
        $locked = $kept->newQueryWithoutRelationships()
            ->whereKey([$kept->getKey(), $duplicate->getKey()])
            // One lock order for every merge, so two opposite merges of the same pair wait instead of deadlocking.
            ->orderBy($kept->getKeyName())
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (Model $record): string => (string) $record->getKey());

        if ($locked->count() !== 2) {
            throw $this->unavailable();
        }

        return [$locked[(string) $kept->getKey()], $locked[(string) $duplicate->getKey()]];
    }

    private function assertWithinLimits(MergedValues $mergedValues): void
    {
        $over = Arr::first($mergedValues->overLimit);

        if ($over === null) {
            return;
        }

        throw ValidationException::withMessages([
            'duplicate_id' => __('filament/record-page.merge.over_limit', ['field' => $over['label'], 'max' => $over['limit']]),
        ]);
    }

    private function unavailable(): ValidationException
    {
        return ValidationException::withMessages(['duplicate_id' => __('filament/record-page.merge.unavailable')]);
    }
}
