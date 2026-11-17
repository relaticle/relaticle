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

    public function execute(User $user, Company|People $priority, Company|People $other): Company|People
    {
        abort_unless($user->can('create', $priority::class) && $user->can('delete', $priority) && $user->can('delete', $other), 403);

        if ($priority::class !== $other::class || $priority->is($other) || $priority->workspace_id !== $other->workspace_id) {
            throw $this->unavailable();
        }

        return DB::transaction(function () use ($user, $priority, $other): Company|People {
            [$priority, $other] = $this->lock($priority, $other);

            $merged = $this->createMergedRecord($priority, $other);
            $mergedValues = $this->combine->execute($priority, $other, $merged);

            $this->assertWithinLimits($mergedValues);

            foreach ([$priority, $other] as $original) {
                $this->moveLinks->execute($merged, $original, $mergedValues);
                $this->moveEntityLinks($merged, $original);
                event(new RecordMerged($merged, $original));
            }

            $this->moveHistory->execute($user, $merged, $priority, $other);

            $priority->delete();
            $other->delete();

            return activity()->withoutLogging(fn (): Company|People => $this->saveMergedValues($user, $merged, $mergedValues));
        });
    }

    private function createMergedRecord(Company|People $priority, Company|People $other): Company|People
    {
        $origin = $priority->creation_source === CreationSource::SAMPLE && $other->creation_source !== CreationSource::SAMPLE ? $other : $priority;

        // Muted so the new record gets no `created` row, no favicon fetch before it has domains, and no onboarding turn for an `mcp` source.
        return Model::withoutEvents(fn (): Company|People => $priority->newQueryWithoutRelationships()->forceCreate([
            'name' => $priority->name,
            'workspace_id' => $priority->workspace_id,
            'creator_id' => $origin->creator_id,
            'creation_source' => $origin->creation_source,
        ]));
    }

    private function moveEntityLinks(Company|People $merged, Company|People $original): void
    {
        match (true) {
            $merged instanceof Company => $this->moveCompanyLinks($merged, (string) $original->getKey()),
            $merged instanceof People => DB::table('opportunities')
                ->where('contact_id', $original->getKey())
                ->update(['contact_id' => $merged->getKey(), 'updated_at' => now()]),
        };
    }

    private function moveCompanyLinks(Company $merged, string $originalId): void
    {
        DB::table('people')->where('company_id', $originalId)->update(['company_id' => $merged->getKey(), 'updated_at' => now()]);
        DB::table('opportunities')->where('company_id', $originalId)->update(['company_id' => $merged->getKey(), 'updated_at' => now()]);
        $this->moveLogoIfMergedHasNone($merged, $originalId);
    }

    private function moveLogoIfMergedHasNone(Company $merged, string $originalId): void
    {
        $logoOf = fn (string $recordId): Builder => DB::table('media')
            ->where('model_type', $merged->getMorphClass())
            ->where('model_id', $recordId)
            ->where('collection_name', MediaCollection::Logo->value);

        if (! $logoOf((string) $merged->getKey())->exists()) {
            $logoOf($originalId)->update(['model_id' => $merged->getKey()]);
        }
    }

    private function saveMergedValues(User $user, Company|People $merged, MergedValues $mergedValues): Company|People
    {
        $data = [...$mergedValues->attributes, 'custom_fields' => $mergedValues->customFields];

        return match ($merged::class) {
            Company::class => $this->updateCompany->execute($user, $merged, $data),
            People::class => $this->updatePeople->execute($user, $merged, $data),
        };
    }

    /**
     * @template TRecord of Company|People
     *
     * @param  TRecord  $priority
     * @param  TRecord  $other
     * @return array{0: TRecord, 1: TRecord}
     */
    private function lock(Company|People $priority, Company|People $other): array
    {
        /** @var Collection<string, TRecord> $locked */
        $locked = $priority->newQueryWithoutRelationships()
            ->whereKey([$priority->getKey(), $other->getKey()])
            // One lock order for every merge, so two opposite merges of the same pair wait instead of deadlocking.
            ->orderBy($priority->getKeyName())
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (Model $record): string => (string) $record->getKey());

        if ($locked->count() !== 2) {
            throw $this->unavailable();
        }

        return [$locked[(string) $priority->getKey()], $locked[(string) $other->getKey()]];
    }

    private function assertWithinLimits(MergedValues $mergedValues): void
    {
        $message = Arr::first($mergedValues->overLimit);

        if ($message === null) {
            return;
        }

        throw ValidationException::withMessages(['duplicate_id' => $message]);
    }

    private function unavailable(): ValidationException
    {
        return ValidationException::withMessages(['duplicate_id' => __('filament/record-page.merge.unavailable')]);
    }
}
