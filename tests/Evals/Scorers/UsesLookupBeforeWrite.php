<?php

declare(strict_types=1);

namespace Tests\Evals\Scorers;

use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;
use Tests\Evals\Scorers\Concerns\ReadsEnvelope;

final readonly class UsesLookupBeforeWrite implements Scorer
{
    use ReadsEnvelope;

    private const array WITHOUT_LOOKUP = [
        'ListWorkspaceMembersTool',
        'GetCrmSummaryTool',
        'ListCustomFieldsTool',
        'ListEmailAccountsTool',
        'ListEmailsTool',
        'GetEmailTool',
        'ListActivityTool',
        'GetCreditBalanceTool',
    ];

    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        if (($failed = $this->failedTurn($output)) instanceof ScorerResult) {
            return $failed;
        }

        $heavyRead = null;

        foreach ($this->toolCalls($output) as $call) {
            if (preg_match('/^(Create|Update|Delete|Set|Send|Invite|CancelEmail|RescheduleEmail|RetryEmail|RemoveSampleData)/', $call['name']) === 1 && $heavyRead !== null) {
                return $this->fail("{$call['name']} follows {$heavyRead} without lookup true");
            }

            $isRead = preg_match('/^(List|Get)/', $call['name']) === 1 && ! in_array($call['name'], self::WITHOUT_LOOKUP, true);

            if ($isRead && ! filter_var($call['arguments']['lookup'] ?? false, FILTER_VALIDATE_BOOL)) {
                $heavyRead ??= $call['name'];
            }
        }

        return $this->pass('Every read before a write used lookup true');
    }
}
