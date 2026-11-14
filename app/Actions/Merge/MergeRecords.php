<?php

declare(strict_types=1);

namespace App\Actions\Merge;

use App\Data\MergedValues;
use App\Enums\CreationSource;
use App\Events\RecordMerged;
use App\Models\Company;
use App\Models\People;
use App\Models\User;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class MergeRecords
{
    public function __construct(
        private CombineRecordValues $combine,
        private MoveRecordLinks $moveLinks,
        private MoveRecordHistory $moveHistory,
    ) {}

    /**
     * @template TRecord of Company|People
     *
     * @param  TRecord  $kept
     * @param  TRecord  $duplicate
     * @param  Closure(TRecord, TRecord): void  $moveOwnLinks
     * @param  Closure(TRecord, MergedValues): TRecord  $writeValues
     * @return TRecord
     */
    public function execute(User $user, Company|People $kept, Company|People $duplicate, Closure $moveOwnLinks, Closure $writeValues): Company|People
    {
        abort_unless($user->can('update', $kept) && $user->can('delete', $duplicate), 403);

        if ($kept->is($duplicate) || $kept->workspace_id !== $duplicate->workspace_id) {
            throw $this->unavailable();
        }

        return DB::transaction(function () use ($user, $kept, $duplicate, $moveOwnLinks, $writeValues): Company|People {
            [$kept, $duplicate] = $this->lock($kept, $duplicate);

            $values = $this->combine->execute($kept, $duplicate);

            $this->assertWithinLimits($values);

            if ($kept->creation_source === CreationSource::SAMPLE && $duplicate->creation_source !== CreationSource::SAMPLE) {
                $kept->update(['creation_source' => $duplicate->creation_source]);
            }

            $this->moveLinks->execute($kept, $duplicate);
            $moveOwnLinks($kept, $duplicate);
            $this->moveHistory->execute($user, $kept, $duplicate);

            event(new RecordMerged($kept, $duplicate));

            $duplicate->delete();

            return $writeValues($kept, $values);
        });
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
        $lockedKeys = $kept->newQueryWithoutRelationships()
            ->whereKey([$kept->getKey(), $duplicate->getKey()])
            ->lockForUpdate()
            ->pluck($kept->getKeyName());

        if ($lockedKeys->count() !== 2) {
            throw $this->unavailable();
        }

        return [$kept->fresh() ?? throw $this->unavailable(), $duplicate->fresh() ?? throw $this->unavailable()];
    }

    private function assertWithinLimits(MergedValues $values): void
    {
        $over = Arr::first($values->overLimit);

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
