<?php

declare(strict_types=1);

namespace Tests\Evals\Support;

use Pest\Evals\Contracts\JudgeDriver;
use Pest\Evals\Eval\Evaluation;
use Pest\Evals\Eval\Verdict;

final class RecordingJudge implements JudgeDriver
{
    public static bool $used = false;

    /** @var list<array{level: string|null, score: float, reasoning: string}> */
    public static array $verdicts = [];

    public function __construct(private readonly JudgeDriver $inner) {}

    public function judge(Evaluation $evaluation): Verdict
    {
        self::$used = true;

        $verdict = $this->inner->judge($evaluation);
        self::$verdicts[] = ['level' => $verdict->level, 'score' => $verdict->score, 'reasoning' => $verdict->reasoning];

        return $verdict;
    }
}
