<?php

declare(strict_types=1);

namespace App\Health;

use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Throwable;

final class AnthropicModelCheck extends Check
{
    public function run(): Result
    {
        $model = $this->defaultAnthropicModel();

        $result = Result::make()
            ->meta(['model' => $model])
            ->shortSummary($model);

        try {
            Prism::text()
                ->using(Provider::Anthropic, $model)
                ->withMaxTokens(1)
                ->withPrompt('Hi')
                ->generate();

            return $result->ok();
        } catch (Throwable $e) {
            return $result->failed("Anthropic model '{$model}' is unavailable: {$e->getMessage()}");
        }
    }

    /**
     * The chat model catalogue is user-selectable, so there is no single
     * configured model. Health-check the Anthropic entry available on every
     * plan, which is the one most turns actually use.
     */
    private function defaultAnthropicModel(): string
    {
        /** @var list<array<string, mixed>> $models */
        $models = config('chat.models', []);

        foreach ($models as $entry) {
            if (($entry['provider'] ?? null) !== 'anthropic') {
                continue;
            }

            if (($entry['min_plan'] ?? null) !== 'free') {
                continue;
            }

            $model = $entry['model'] ?? null;

            if (is_string($model) && $model !== '') {
                return $model;
            }
        }

        return 'claude-sonnet-4-6';
    }
}
