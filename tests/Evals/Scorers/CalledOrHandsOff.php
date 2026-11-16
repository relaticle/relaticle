<?php

declare(strict_types=1);

namespace Tests\Evals\Scorers;

use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;
use Tests\Evals\Scorers\Concerns\ReadsEnvelope;

final readonly class CalledOrHandsOff implements Scorer
{
    use ReadsEnvelope;

    private const string LINK = '/\]\([^)\s]*\/chats(\?[^)\s]*)?\)/';

    private const string DEAD_END = '/record page/i';

    /**
     * @param  list<string>  $names
     */
    public function __construct(private array $names) {}

    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        if (($failed = $this->failedTurn($output)) instanceof ScorerResult) {
            return $failed;
        }

        foreach ($this->toolCalls($output) as $call) {
            if (in_array($call['name'], $this->names, true)) {
                return $this->pass("Called {$call['name']}");
            }
        }

        $reply = $this->replyText($output);

        if (preg_match(self::LINK, $reply) !== 1) {
            return $this->fail('Called none of '.implode(', ', $this->names).' and the reply holds no link to a new chat');
        }

        if (preg_match(self::DEAD_END, $reply) === 1) {
            return $this->fail('The reply links a page but still sends the user to a record page');
        }

        return $this->pass('The reply hands off with a link');
    }
}
