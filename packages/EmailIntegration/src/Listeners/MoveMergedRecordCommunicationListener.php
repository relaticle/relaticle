<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Listeners;

use App\Events\RecordMerged;
use Relaticle\EmailIntegration\Actions\MoveMergedRecordCommunication;

final readonly class MoveMergedRecordCommunicationListener
{
    public function __construct(private MoveMergedRecordCommunication $move) {}

    public function handle(RecordMerged $event): void
    {
        $this->move->execute($event->kept, $event->duplicate);
    }
}
