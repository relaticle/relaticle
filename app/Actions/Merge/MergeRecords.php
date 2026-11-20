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
        private RaiseFieldLimits $raiseLimits,
        private UpdateCompany $updateCompany,
        private UpdatePeople $updatePeople,
    ) {}

    public function execute(User $user, Company|People $priority, Company|People $other): Company|People
    {
        abort_unless($user->can('create', $priority::class) && $user->can('update', $priority) && $user->can('delete', $priority) && $user->can('delete', $other), 403);

        if ($priority::class !== $other::class || $priority->is($other) || $priority->workspace_id !== $other->workspace_id) {
            throw $this->unavailable();
        }

        return DB::transaction(function () use ($user, $priority, $other): Company|People {
            [$priority, $other] = $this->lock($priority, $other);

            $origin = $this->origin($priority, $other);
            $merged = $this->createMergedRecord($priority, $origin);
            $mergedValues = $this->combine->execute($priority, $other, $merged);

            $this->assertWithinLimits($mergedValues);
            $this->raiseLimits->execute($merged, $mergedValues);

            foreach ([$priority, $other] as $original) {
                $this->moveLinks->execute($merged, $original, $mergedValues);
                $this->moveEntityLinks($merged, $original);
                event(new RecordMerged($merged, $original));
            }

            $this->markMergedInto($merged, $priority, $other);

            $merged = activity()->withoutLogging(function () use ($user, $merged, $mergedValues, $priority, $other): Company|People {
                $priority->delete();
                $other->delete();

                return $this->saveMergedValues($user, $merged, $mergedValues);
            });
            $this->moveHistory->execute($user, $merged, $origin, $priority, $other);

            return $merged;
        });
    }

    private function markMergedInto(Company|People $merged, Company|People $priority, Company|People $other): void
    {
        $priority->newQueryWithoutRelationships()
            ->where('workspace_id', $merged->workspace_id)
            ->whereKey([$priority->getKey(), $other->getKey()])
            ->update(['merged_into_id' => $merged->getKey()]);
    }

    private function origin(Company|People $priority, Company|People $other): Company|People
    {
        return $priority->creation_source === CreationSource::SAMPLE && $other->creation_source !== CreationSource::SAMPLE ? $other : $priority;
    }

    private function createMergedRecord(Company|People $priority, Company|People $origin): Company|People
    {
        // Muted so the new record gets no `created` row, no favicon fetch before it has domains, and no onboarding turn for an `mcp` source.
        return Model::withoutEvents(fn (): Company|People => $priority->newQueryWithoutRelationships()->forceCreate([
            'name' => $priority->name,
            'workspace_id' => $priority->workspace_id,
            'creator_id' => $origin->creator_id,
            'creation_source' => $origin->creation_source,
            'created_at' => $origin->created_at,
        ]));
    }

    private function moveEntityLinks(Company|People $merged, Company|People $original): void
    {
        match (true) {
            $merged instanceof Company => $this->moveCompanyLinks($merged, (string) $original->getKey()),
            $merged instanceof People => DB::table('opportunities')
                ->where('workspace_id', $merged->workspace_id)
                ->where('contact_id', $original->getKey())
                ->update(['contact_id' => $merged->getKey(), 'updated_at' => now()]),
        };
    }

    private function moveCompanyLinks(Company $merged, string $originalId): void
    {
        foreach (['people', 'opportunities'] as $table) {
            DB::table($table)
                ->where('workspace_id', $merged->workspace_id)
                ->where('company_id', $originalId)
                ->update(['company_id' => $merged->getKey(), 'updated_at' => now()]);
        }

        $this->moveLogoIfMergedHasNone($merged, $originalId);
    }

    private function moveLogoIfMergedHasNone(Company $merged, string $originalId): void
    {
        $logoOf = fn (string $recordId): Builder => DB::table('media')
            ->where('workspace_id', $merged->workspace_id)
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

        if ($message !== null) {
            throw ValidationException::withMessages(['records' => $message]);
        }
    }

    private function unavailable(): ValidationException
    {
        return ValidationException::withMessages(['records' => __('filament/record-page.merge.unavailable')]);
    }
}
