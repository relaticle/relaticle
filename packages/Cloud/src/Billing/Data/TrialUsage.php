<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Data;

use Illuminate\Support\Number;

final readonly class TrialUsage
{
    public function __construct(
        public int $records,
        public int $questions,
    ) {}

    public function isEmpty(): bool
    {
        return $this->records === 0 && $this->questions === 0;
    }

    public function parts(): string
    {
        $records = trans_choice('mail.trial_usage.records', $this->records, ['count' => Number::format($this->records)]);
        $questions = trans_choice('mail.trial_usage.questions', $this->questions, ['count' => Number::format($this->questions)]);

        return match (true) {
            $this->questions === 0 => $records,
            $this->records === 0 => $questions,
            default => __('mail.trial_usage.both', ['records' => $records, 'questions' => $questions]),
        };
    }
}
