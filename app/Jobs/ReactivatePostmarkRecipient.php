<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Notifications\PostmarkSuppressionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ReactivatePostmarkRecipient implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $email) {}

    public function handle(PostmarkSuppressionService $service): void
    {
        $service->reactivate($this->email);
    }
}
