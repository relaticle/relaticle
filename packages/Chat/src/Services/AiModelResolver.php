<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Enums\Plan;
use App\Models\User;
use Relaticle\Chat\Support\ModelDescriptor;
use RuntimeException;

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
     * `source` records whether the resolution was the user's explicit pick or
     * the auto chain: ProcessChatMessage only fails over to the next chain
     * entry when it is 'auto'. An explicit pick that fails must surface as an
     * error, never a silent swap to a different (differently priced) model.
     *
     * @return array{provider: string|null, model: string|null, id: string|null, source: string}
     */
    public function resolve(User $user, ?string $override = null): array
    {
        $team = $user->currentTeam;
        $plan = $team !== null ? $team->plan : Plan::default();
        $requested = $override ?? ($user->ai_preferences['default_model'] ?? 'auto');

        if (is_string($requested) && $requested !== 'auto') {
            $descriptor = $this->registry->find($requested);

            if ($descriptor instanceof ModelDescriptor && $descriptor->isAvailable() && $descriptor->allowedForPlan($plan)) {
                return $this->describe($descriptor, 'explicit');
            }
        }

        return $this->describe($this->autoPick($plan), 'auto');
    }

    /**
     * The next available, plan-allowed model after $failedId in the auto
     * chain, or null once the chain is exhausted. Only ever used for an
     * 'auto' resolution. An explicit pick never calls this.
     *
     * @return array{provider: string|null, model: string|null, id: string|null, source: string}|null
     */
    public function failoverNext(User $user, string $failedId): ?array
    {
        $team = $user->currentTeam;
        $plan = $team !== null ? $team->plan : Plan::default();
        $passed = false;

        foreach ($this->registry->autoChain() as $descriptor) {
            if ($descriptor->id === $failedId) {
                $passed = true;

                continue;
            }

            if ($passed && $descriptor->isAvailable() && $descriptor->allowedForPlan($plan)) {
                return $this->describe($descriptor, 'auto');
            }
        }

        return null;
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

        return $this->registry->find('claude-sonnet')
            ?? $chain[0]
            ?? $this->registry->all()[0]
            ?? throw new RuntimeException('No chat model is configured; set at least one provider in config/chat.php.');
    }

    /**
     * @return array{provider: string|null, model: string|null, id: string|null, source: string}
     */
    private function describe(ModelDescriptor $descriptor, string $source): array
    {
        return [
            'provider' => $descriptor->provider,
            'model' => $descriptor->model,
            'id' => $descriptor->id,
            'source' => $source,
        ];
    }
}
