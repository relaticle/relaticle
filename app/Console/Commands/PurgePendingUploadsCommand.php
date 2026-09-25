<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MediaCollection;
use App\Support\Media\TemporaryUploads;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Description('Delete pending uploads and signed-put temp files nobody claimed within a day')]
#[Signature('app:purge-pending-uploads {--hours=24 : Age after which an unclaimed upload is deleted}')]
final class PurgePendingUploadsCommand extends Command
{
    public function handle(): int
    {
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($hours === false) {
            $this->error(__('uploads.errors.invalid_retention'));

            return self::FAILURE;
        }

        $cutoff = now()->subHours($hours);

        $media = 0;
        $candidateIds = Media::query()
            ->where('collection_name', MediaCollection::PendingUploads->value)
            ->where('created_at', '<', $cutoff)
            ->select('id')
            ->lazyById();

        foreach ($candidateIds as $candidate) {
            $deleted = DB::transaction(function () use ($candidate, $cutoff): bool {
                $row = Media::query()
                    ->whereKey($candidate->getKey())
                    ->where('collection_name', MediaCollection::PendingUploads->value)
                    ->where('created_at', '<', $cutoff)
                    ->lockForUpdate()
                    ->first();

                return $row instanceof Media && $row->delete();
            });

            $media += (int) $deleted;
        }

        $disk = TemporaryUploads::disk();
        $temporary = 0;

        foreach ($disk->files(TemporaryUploads::DIRECTORY) as $file) {
            if ($disk->lastModified($file) >= $cutoff->getTimestamp()) {
                continue;
            }

            $temporary += (int) $disk->delete($file);
        }

        $this->comment("Purged {$media} pending upload(s) and {$temporary} temp file(s).");

        return self::SUCCESS;
    }
}
