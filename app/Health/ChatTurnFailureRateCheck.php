<?php

declare(strict_types=1);

namespace App\Health;

use Relaticle\Chat\Models\AgentConversationMessage;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

final class ChatTurnFailureRateCheck extends Check
{
    private const int WINDOW_MINUTES = 10;

    private const int MIN_TURNS = 5;

    private const int MAX_FAILED_PERCENT = 20;

    public function run(): Result
    {
        $recent = AgentConversationMessage::query()
            ->where('role', 'assistant')
            ->where('created_at', '>=', now()->subMinutes(self::WINDOW_MINUTES));

        $total = $recent->clone()->count();
        $failed = $recent->clone()->errored()->count();

        $result = Result::make()
            ->meta(['total' => $total, 'failed' => $failed])
            ->shortSummary("{$failed}/{$total}");

        if ($total < self::MIN_TURNS) {
            return $result->ok();
        }

        if ($failed * 100 <= $total * self::MAX_FAILED_PERCENT) {
            return $result->ok();
        }

        return $result->failed(sprintf('%d of %d chat turns failed in the last %d minutes', $failed, $total, self::WINDOW_MINUTES));
    }
}
