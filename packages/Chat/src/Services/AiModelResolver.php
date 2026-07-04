<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Enums\Plan;
use App\Models\User;
use Relaticle\Chat\Enums\AiModel;

final readonly class AiModelResolver
{
    /**
     * Resolve the provider and model for a chat request.
     *
     * `Auto` (and any unavailable or plan-disallowed request) resolves to the
     * first available, plan-allowed model in the priority chain: Claude
     * Sonnet, then GPT-5.5, then Ollama. When the plan allows none of the
     * available models (a self-hosted install whose only configured provider
     * is plan-gated, e.g. OpenAI-only on a Free team), the first available
     * model wins regardless of plan -- self-hosted infrastructure is not
     * plan-gated. Smaller models like Haiku cannot be trusted to call CRM
     * write tools reliably -- they tend to hallucinate "task created" without
     * invoking the tool.
     *
     * @return array{provider: string|null, model: string|null}
     */
    public function resolve(User $user, ?string $override = null): array
    {
        $aiModel = $this->resolveModel($user, $override);

        $team = $user->currentTeam;
        $plan = $team !== null ? $team->plan : Plan::default();

        if (! $plan->allowsModel($aiModel) || ! $aiModel->available()) {
            $aiModel = AiModel::Auto;
        }

        if ($aiModel === AiModel::Auto) {
            $aiModel = $this->defaultFor($plan);
        }

        return [
            'provider' => $aiModel->provider(),
            'model' => $aiModel->modelId(),
        ];
    }

    private function resolveModel(User $user, ?string $override): AiModel
    {
        if ($override !== null) {
            $model = AiModel::tryFrom($override);

            if ($model !== null && $model !== AiModel::Auto) {
                return $model;
            }
        }

        $preference = $user->ai_preferences['default_model'] ?? 'auto';
        $model = AiModel::tryFrom($preference);

        if ($model !== null && $model !== AiModel::Auto) {
            return $model;
        }

        return AiModel::Auto;
    }

    private function defaultFor(Plan $plan): AiModel
    {
        $chain = [AiModel::ClaudeSonnet, AiModel::Gpt5_5, AiModel::Ollama];

        foreach ($chain as $candidate) {
            if ($candidate->available() && $plan->allowsModel($candidate)) {
                return $candidate;
            }
        }

        foreach ($chain as $candidate) {
            if ($candidate->available()) {
                return $candidate;
            }
        }

        return AiModel::ClaudeSonnet;
    }
}
