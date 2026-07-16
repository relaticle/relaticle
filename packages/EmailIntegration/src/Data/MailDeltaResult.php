<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Data;

use Illuminate\Support\Collection;

final readonly class MailDeltaResult
{
    /**
     * @param  Collection<int, string>  $messageIds  Provider message IDs newly created since cursor
     * @param  Collection<int, string>  $readMessageIds  Provider message IDs that became read since cursor
     * @param  string  $newCursor  Opaque cursor (Gmail historyId, Microsoft Graph deltaLink)
     * @param  Collection<int, string>|null  $unreadMessageIds  Provider message IDs that became unread since cursor
     */
    public function __construct(
        public Collection $messageIds,
        public Collection $readMessageIds,
        public string $newCursor,
        public ?Collection $unreadMessageIds = null,
    ) {}
}
