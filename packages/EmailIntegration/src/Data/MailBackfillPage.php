<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Data;

use Illuminate\Support\Collection;

final readonly class MailBackfillPage
{
    /**
     * @param  Collection<int, string>  $messageIds
     * @param  string|null  $nextPageToken  Gmail page token or Graph @odata.nextLink; null when this is the last page
     * @param  string|null  $cursor  Gmail historyId (first page) or Graph deltaLink (last page)
     */
    public function __construct(
        public Collection $messageIds,
        public ?string $nextPageToken,
        public ?string $cursor,
        public ?int $estimatedTotal = null,
    ) {}
}
