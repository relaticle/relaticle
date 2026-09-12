<?php

declare(strict_types=1);

namespace App\Support\Media;

use Illuminate\Support\Facades\URL;
use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;

final class MediaUrlGenerator extends DefaultUrlGenerator
{
    private const int SIGNED_URL_MINUTES = 30;

    public function getUrl(): string
    {
        if (config("filesystems.disks.{$this->media->disk}.visibility") === 'public') {
            return parent::getUrl();
        }

        return URL::temporarySignedRoute('media.show', now()->addMinutes(self::SIGNED_URL_MINUTES), ['media' => $this->media->uuid]);
    }
}
