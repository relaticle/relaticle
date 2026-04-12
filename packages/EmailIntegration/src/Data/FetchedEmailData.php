<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Data;

use Illuminate\Support\Carbon;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;

final readonly class FetchedEmailData
{
    /**
     * @param  array<int, array{email_address: string, name: string|null, role: string}>  $participants
     * @param  array<int, array{filename: string|null, mime_type: string|null, size: int, content_id: string|null, attachment_id: string|null, inline_data: string|null}>  $attachments
     */
    public function __construct(
        public string $providerMessageId,
        public ?string $rfcMessageId,
        public string $threadId,
        public ?string $inReplyTo,
        public ?string $subject,
        public ?string $snippet,
        public Carbon $sentAt,
        public EmailDirection $direction,
        public ?EmailFolder $folder,
        public bool $hasAttachments,
        public ?string $bodyText,
        public ?string $bodyHtml,
        public array $participants,
        public array $attachments,
    ) {}
}
