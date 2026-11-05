<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Analytics\Listeners;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Laravel\Cashier\Events\WebhookHandled;
use Relaticle\Cloud\Analytics\Actions\RecordSubscriptionOutcome;

#[Queue('imports')]
#[Tries(3)]
#[Backoff([30, 120])]
#[Timeout(240)]
final readonly class CaptureSubscriptionOutcome implements ShouldQueueAfterCommit
{
    public function __construct(private RecordSubscriptionOutcome $recordOutcome) {}

    public function shouldQueue(WebhookHandled $event): bool
    {
        return in_array($event->payload['type'] ?? null, ['invoice.paid', 'invoice.payment_failed'], true);
    }

    public function handle(WebhookHandled $event): void
    {
        $this->recordOutcome->execute($event->payload);
    }
}
