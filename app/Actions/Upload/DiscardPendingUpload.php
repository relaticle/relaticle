<?php

declare(strict_types=1);

namespace App\Actions\Upload;

use App\Enums\MediaCollection;
use App\Models\Team;
use App\Support\Media\MediaPaths;

final readonly class DiscardPendingUpload
{
    public function __construct(private MediaPaths $paths) {}

    public function execute(Team $team, string $path): void
    {
        $media = $this->paths->find((string) $team->getKey(), $path);

        if ($media?->collection_name === MediaCollection::PendingUploads->value) {
            $media->delete();
        }
    }
}
