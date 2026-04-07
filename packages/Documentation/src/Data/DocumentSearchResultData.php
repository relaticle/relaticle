<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Data;

use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Url;
use Spatie\LaravelData\Attributes\WithoutValidation;
use Spatie\LaravelData\Data;

final class DocumentSearchResultData extends Data
{
    public function __construct(
        #[StringType]
        public string $type,

        #[StringType]
        public string $title,

        #[StringType]
        public string $excerpt,

        #[WithoutValidation]
        #[Url]
        public string $url,

        #[WithoutValidation]
        public float $relevance = 0.0,
    ) {}

    /**
     * Generate a URL for this search result
     */
    public static function generateUrl(string $type): string
    {
        return route('documentation.show', ['type' => $type]);
    }
}
