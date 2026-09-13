<?php

declare(strict_types=1);

namespace App\Support;

use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;

/**
 * Media on a local disk is served from `app.url`, but the app panel answers on its
 * own subdomain. Filament's file upload previews the stored file with `fetch()`,
 * which a cross-origin response without CORS headers rejects, leaving the field
 * spinning forever. Both hosts serve the same public directory, so pointing the
 * URL at the requesting host keeps the preview same-origin.
 */
final class SameOriginUrlGenerator extends DefaultUrlGenerator
{
    public function getUrl(): string
    {
        return SameOriginUrl::rewrite(parent::getUrl());
    }
}
