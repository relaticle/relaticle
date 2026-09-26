<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Data;

final readonly class MailboxHistoryImportSummary
{
    public function __construct(
        public int $totalJobs,
        public int $successfulJobs,
        public int $failedJobs,
        public bool $finished,
    ) {}
}
