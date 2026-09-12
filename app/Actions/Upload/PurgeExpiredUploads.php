<?php

declare(strict_types=1);

namespace App\Actions\Upload;

use App\Enums\MediaCollection;
use App\Support\Media\TemporaryUploads;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class PurgeExpiredUploads
{
    /** @return array{media: int, temporary: int} */
    public function execute(CarbonImmutable $cutoff): array
    {
        $mediaCount = 0;
        $candidateIds = Media::query()
            ->where('collection_name', MediaCollection::PendingUploads->value)
            ->where('created_at', '<', $cutoff)
            ->select('id')
            ->lazyById();

        foreach ($candidateIds as $candidate) {
            $deleted = DB::transaction(function () use ($candidate, $cutoff): bool {
                $media = Media::query()
                    ->whereKey($candidate->getKey())
                    ->where('collection_name', MediaCollection::PendingUploads->value)
                    ->where('created_at', '<', $cutoff)
                    ->lockForUpdate()
                    ->first();

                return $media instanceof Media && $media->delete();
            });

            $mediaCount += (int) $deleted;
        }

        $disk = TemporaryUploads::disk();
        $temporaryCount = 0;

        foreach ($disk->files(TemporaryUploads::DIRECTORY) as $file) {
            if ($disk->lastModified($file) >= $cutoff->getTimestamp()) {
                continue;
            }

            $temporaryCount += (int) $disk->delete($file);
        }

        return ['media' => $mediaCount, 'temporary' => $temporaryCount];
    }
}
