<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Enums\Plan;
use App\Models\User;
use Relaticle\Chat\Support\ModelDescriptor;

final readonly class AiModelResolver
{
    public function __construct(private ModelRegistry $registry) {}

    /**
     * Resolve the provider and model for a chat request. An available,
     * plan-allowed explicit choice (or stored user preference) is honored;
     * anything else falls to the configured `auto_chain`: first available +
     * plan-allowed, then first available regardless of plan (self-hosted
     * infrastructure is not plan-gated), then a safe cloud default.
     *
     * @return array{provider: string|null, model: string|null}
     */
    public function resolve(User $user, ?string $override = null): array
    {
        $descriptor = $this->pick($user, $override);

        return ['provider' => $descriptor->provider, 'model' => $descriptor->model];
    }

    private function pick(User $user, ?string $override): ModelDescriptor
    {
        $team = $user->currentTeam;
        $plan = $team !== null ? $team->plan : Plan::default();

        $requested = $override ?? ($user->ai_preferences['default_model'] ?? 'auto');

        if (is_string($requested) && $requested !== 'auto') {
            $descriptor = $this->registry->find($requested);

            if ($descriptor instanceof ModelDescriptor && $descriptor->isAvailable() && $descriptor->allowedForPlan($plan)) {
                return $descriptor;
            }
        }

        return $this->autoPick($plan);
    }

    private function autoPick(Plan $plan): ModelDescriptor
    {
        $chain = $this->registry->autoChain();

        foreach ($chain as $descriptor) {
            if ($descriptor->isAvailable() && $descriptor->allowedForPlan($plan)) {
                return $descriptor;
            }
        }

        foreach ($chain as $descriptor) {
            if ($descriptor->isAvailable()) {
                return $descriptor;
            }
        }

        return $this->registry->find('claude-sonnet') ?? $chain[0];
    }
}
