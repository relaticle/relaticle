<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Enums\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;

final class UploadPathGenerator extends DefaultPathGenerator
{
    protected function getBasePath(Media $media): string
    {
        if ($media->collection_name === MediaCollection::Logo->value) {
            return parent::getBasePath($media);
        }

        return "uploads/{$media->uuid}";
    }
}
