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
        $model = config('services.anthropic.summary_model');

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
}
