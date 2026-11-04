<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Data;

use App\Enums\CrmEntity;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use Carbon\CarbonImmutable;
use Relaticle\EmailIntegration\Enums\EmailCategory;
use Relaticle\EmailIntegration\Enums\EmailDirection;

final readonly class ConversationFilters
{
    public function __construct(
        public ?string $mailboxId = null,
        public ?EmailDirection $direction = null,
        public ?bool $hasAttachment = null,
        public ?CarbonImmutable $sentAfter = null,
        public ?CarbonImmutable $sentBefore = null,
        public ?CrmEntity $recordType = null,
        public ?string $recordId = null,
        public ?EmailCategory $category = null,
        public ?string $search = null,
    ) {}

    public static function forRecord(Company|People|Opportunity $record): self
    {
        return new self(
            recordType: CrmEntity::from($record->getMorphClass()),
            recordId: (string) $record->getKey(),
        );
    }
}
