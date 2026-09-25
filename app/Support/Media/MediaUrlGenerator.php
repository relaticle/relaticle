<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Support\SameOriginUrl;
use Illuminate\Support\Facades\URL;
use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;

final class MediaUrlGenerator extends DefaultUrlGenerator
{
    private const int SIGNED_URL_MINUTES = 30;

    public function getUrl(): string
    {
        // A signed route is bound to the host it was signed on, so only the
        // public URL is rewritten to the requesting host for a same-origin preview.
        if (config("filesystems.disks.{$this->media->disk}.visibility") === 'public') {
            return SameOriginUrl::rewrite(parent::getUrl());
        }

        return URL::temporarySignedRoute('media.show', now()->addMinutes(self::SIGNED_URL_MINUTES), ['media' => $this->media->uuid]);
    }
}
