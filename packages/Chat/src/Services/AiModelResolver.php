<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Contracts\Entitlements;
use App\Models\User;
use App\Models\Workspace;
use Relaticle\Chat\Support\ModelDescriptor;
use RuntimeException;

final readonly class AiModelResolver
{
    public function __construct(private ModelRegistry $registry, private Entitlements $entitlements) {}

    /**
     * Resolve the provider and model for a chat request. An available,
     * plan-allowed explicit choice (or stored user preference) is honored;
     * anything else falls to the Auto chain: first available + plan-allowed, then
     * first available regardless of plan (self-hosted infrastructure is not
     * plan-gated), then the head of the chain even though nothing can serve it,
     * so the caller gets a named model to report rather than an exception. No
     * model id is hardcoded here: the catalog is editable without a deploy, so a
     * literal one would be a code change on the next vendor retirement.
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
        $workspace = $user->currentWorkspace;
        $requested = $override ?? ($user->ai_preferences['default_model'] ?? 'auto');

        if (is_string($requested) && $requested !== 'auto') {
            $descriptor = $this->registry->find($requested);

            if ($descriptor instanceof ModelDescriptor && $descriptor->isAvailable() && $this->entitlements->allowsModel($workspace, $descriptor)) {
                return $this->describe($descriptor, 'explicit');
            }
        }

        return $this->describe($this->autoPick($workspace), 'auto');
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
        $workspace = $user->currentWorkspace;
        $passed = false;

        foreach ($this->registry->autoChain() as $descriptor) {
            if ($descriptor->id === $failedId) {
                $passed = true;

                continue;
            }

            if ($passed && $descriptor->isAvailable() && $this->entitlements->allowsModel($workspace, $descriptor)) {
                return $this->describe($descriptor, 'auto');
            }
        }

        return null;
    }

    public function autoOffersAnotherModel(User $user, string $failedId): bool
    {
        $workspace = $user->currentWorkspace;

        return array_any(
            $this->registry->autoChain(),
            fn (ModelDescriptor $descriptor): bool => $descriptor->id !== $failedId
                && $descriptor->isAvailable()
                && $this->entitlements->allowsModel($workspace, $descriptor),
        );
    }

    private function autoPick(?Workspace $workspace): ModelDescriptor
    {
        $chain = $this->registry->autoChain();

        foreach ($chain as $descriptor) {
            if ($descriptor->isAvailable() && $this->entitlements->allowsModel($workspace, $descriptor)) {
                return $descriptor;
            }
        }

        foreach ($chain as $descriptor) {
            if ($descriptor->isAvailable()) {
                return $descriptor;
            }
        }

        return $chain[0]
            ?? $this->registry->all()[0]
            ?? throw new RuntimeException('No chat model is configured; add one at /sysadmin/ai-models or seed one in config/chat.php.');
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
