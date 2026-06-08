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
     * `Auto` always resolves to Claude Sonnet. Smaller models like Haiku
     * cannot be trusted to call CRM write tools reliably -- they tend to
     * hallucinate "task created" without invoking the tool.
     *
     * @return array{provider: string|null, model: string|null}
     */
    public function resolve(User $user, ?string $override = null): array
    {
        $aiModel = $this->resolveModel($user, $override);

        $team = $user->currentTeam;
        $plan = $team !== null ? $team->plan : Plan::default();

        if (! $plan->allowsModel($aiModel)) {
            $aiModel = AiModel::ClaudeSonnet;
        }

        if ($aiModel->provider() === 'gemini') {
            $aiModel = AiModel::ClaudeSonnet;
        }

        if ($aiModel === AiModel::Auto) {
            $aiModel = AiModel::ClaudeSonnet;
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
}
