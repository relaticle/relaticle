<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MediaCollection;
use App\Jobs\FetchFaviconForCompany;
use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

#[Description('Remove stored logos and profile photos that are not raster images, such as SVGs that can carry script')]
#[Signature('media:purge-unsafe-images {--force : Remove the images instead of reporting them}')]
final class PurgeUnsafeImagesCommand extends Command
{
    private bool $write = false;

    public function handle(): int
    {
        $this->write = (bool) $this->option('force');

        $purged = $this->purgeLogos() + $this->purgeProfilePhotos();

        $this->comment($this->write
            ? "{$purged} unsafe image(s) removed."
            : "{$purged} unsafe image(s) would be removed. Re-run with --force to remove them.");

        return self::SUCCESS;
    }

    private function purgeLogos(): int
    {
        $purged = 0;

        $logos = Media::query()
            ->where('collection_name', MediaCollection::Logo->value)
            ->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $query): Builder => $query
                    ->where('model_type', (new Company)->getMorphClass())
                    ->whereNotIn('mime_type', array_keys(Company::LOGO_MIME_TYPES)))
                ->orWhere(fn (Builder $query): Builder => $query
                    ->where('model_type', (new Workspace)->getMorphClass())
                    ->whereNotIn('mime_type', Workspace::LOGO_MIME_TYPES)))
            ->lazyById();

        foreach ($logos as $logo) {
            $this->info("Logo {$logo->getKey()} on {$logo->model_type} {$logo->model_id}: [{$logo->mime_type}]");

            $purged++;

            if (! $this->write) {
                continue;
            }

            try {
                $owner = $logo->model;
                $logo->delete();

                if ($owner instanceof Company) {
                    dispatch(new FetchFaviconForCompany($owner));
                }
            } catch (Throwable $exception) {
                $this->warn("Logo {$logo->getKey()}: {$exception->getMessage()}, skipped.");
            }
        }

        return $purged;
    }

    private function purgeProfilePhotos(): int
    {
        $disk = Storage::disk((string) config('jetstream.profile_photo_disk', 'public'));
        $purged = 0;

        foreach (User::query()->whereNotNull('profile_photo_path')->lazyById() as $user) {
            $path = (string) $user->profile_photo_path;

            if (! $disk->exists($path) || in_array($disk->mimeType($path), User::PROFILE_PHOTO_MIME_TYPES, true)) {
                continue;
            }

            $this->info("Profile photo of user {$user->getKey()}: {$path}");

            $purged++;

            if (! $this->write) {
                continue;
            }

            $user->deleteProfilePhoto();
        }

        return $purged;
    }
}
