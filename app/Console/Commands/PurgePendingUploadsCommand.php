<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MediaCollection;
use App\Support\Media\TemporaryUploads;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Description('Delete pending uploads and signed-put temp files nobody claimed within a day')]
#[Signature('app:purge-pending-uploads {--hours=24 : Age after which an unclaimed upload is deleted}')]
final class PurgePendingUploadsCommand extends Command
{
    public function handle(): int
    {
        $cutoff = now()->subHours((int) $this->option('hours'));

        $media = Media::query()
            ->where('collection_name', MediaCollection::PendingUploads->value)
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($media as $item) {
            $this->info("Deleting pending upload {$item->uuid}");
            $item->delete();
        }

        $disk = TemporaryUploads::disk();
        $temps = 0;

        foreach ($disk->files(TemporaryUploads::DIRECTORY) as $file) {
            if ($disk->lastModified($file) >= $cutoff->getTimestamp()) {
                continue;
            }

            $this->info("Deleting temp file {$file}");
            $disk->delete($file);
            $temps++;
        }

        $this->comment("Purged {$media->count()} pending upload(s) and {$temps} temp file(s).");

        return self::SUCCESS;
    }
}
