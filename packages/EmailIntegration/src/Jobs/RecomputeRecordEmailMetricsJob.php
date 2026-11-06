<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Support\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\UniqueFor;
use Relaticle\EmailIntegration\Services\RecordCommunicationMetrics;

#[DeleteWhenMissingModels]
#[Queue('emails-sync')]
#[Timeout(300)]
#[UniqueFor(600)]
final class RecomputeRecordEmailMetricsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly People|Company|Opportunity $record,
    ) {}

    public function uniqueId(): string
    {
        return "recompute-record-email-metrics-{$this->record->getMorphClass()}-{$this->record->getKey()}";
    }

    public function handle(CurrentWorkspace $currentWorkspace, RecordCommunicationMetrics $metrics): void
    {
        $currentWorkspace->within((string) $this->record->workspace_id, function () use ($metrics): void {
            $metrics->recomputeEmailMetrics($this->record);
        });
    }
}
