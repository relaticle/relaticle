<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Data;

use Carbon\CarbonInterface;
use Relaticle\EmailIntegration\Enums\EmailCategory;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;

final readonly class FetchedEmailData
{
    public const array KEPT_HEADERS = ['list-unsubscribe', 'list-id', 'precedence', 'auto-submitted'];

    /**
     * @param  array<int, array{email_address: string, name: string|null, role: string}>  $participants
     * @param  array<int, array{filename: string|null, mime_type: string|null, size: int, content_id: string|null, attachment_id: string|null, inline_data: string|null, is_inline?: bool}>  $attachments
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $providerMessageId,
        public ?string $rfcMessageId,
        public string $threadId,
        public ?string $inReplyTo,
        public ?string $subject,
        public ?string $snippet,
        public CarbonInterface $sentAt,
        public EmailDirection $direction,
        public ?EmailFolder $folder,
        public bool $hasAttachments,
        public bool $isRead,
        public ?string $bodyText,
        public ?string $bodyHtml,
        public array $participants,
        public array $attachments,
        /**
         * Provider-native category, mapped to our vocabulary during fetch. The
         * classifier uses it only when none of its own rules match.
         */
        public ?EmailCategory $providerCategory = null,
        public ?string $reconciliationMessageId = null,
        public bool $isBulkMail = false,
        public ?string $references = null,
        public array $headers = [],
    ) {}
}
