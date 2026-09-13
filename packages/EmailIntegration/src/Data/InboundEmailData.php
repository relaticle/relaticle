<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Data;

use Carbon\CarbonInterface;

final readonly class InboundEmailData
{
    /**
     * @param  list<array{email_address: string, name: string|null, role: string}>  $participants
     * @param  list<array{filename: string|null, mime_type: string|null, size: int, content_id: string|null, content: string}>  $attachments
     */
    public function __construct(
        public string $rfcMessageId,
        public ?string $inReplyTo,
        public string $subject,
        public ?string $snippet,
        public ?CarbonInterface $sentAt,
        public ?string $bodyText,
        public ?string $bodyHtml,
        public array $participants,
        public array $attachments,
    ) {}
}
