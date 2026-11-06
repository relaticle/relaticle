<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Data;

use Carbon\CarbonImmutable;
use Relaticle\EmailIntegration\Enums\AutomatedReason;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;

final readonly class ConversationRow
{
    /**
     * @param  list<string>  $senderNames
     * @param  list<string>  $participants
     * @param  list<array{type: string, id: string, name: string}>  $records
     */
    public function __construct(
        public string $conversationKey,
        public string $viewerCopyEmailId,
        public ?string $subject,
        public ?string $snippet,
        public array $senderNames,
        public array $participants,
        public int $messageCount,
        public CarbonImmutable $lastMessageAt,
        public bool $hasAttachments,
        public bool $isUnread,
        public bool $isDone,
        public ?string $ownerUserId,
        public ?string $ownerName,
        public EmailPrivacyTier $access,
        public bool $viewerHasPendingRequest,
        public array $records,
        public ?AutomatedReason $automatedReason,
    ) {}
}
