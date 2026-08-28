<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Enums\Plan;
use Relaticle\Chat\Enums\WriteGuard;

final readonly class ModelDescriptor
{
    public function __construct(
        public string $id,
        public string $label,
        public ?string $provider,
        public ?string $model,
        public Plan $minPlan,
        public float $creditMultiplier,
        public bool $supportsTools,
        public WriteGuard $writeGuard,
        public bool $selfHosted,
    ) {}

    /**
     * @param  array{id:string,label:string,provider:?string,model:?string,min_plan:string,credit_multiplier:int|float,supports_tools:bool,write_guard:string,self_hosted:bool}  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            id: $config['id'],
            label: $config['label'],
            provider: $config['provider'] ?? null,
            model: $config['model'] ?? null,
            minPlan: Plan::from($config['min_plan']),
            creditMultiplier: (float) $config['credit_multiplier'],
            supportsTools: (bool) $config['supports_tools'],
            writeGuard: WriteGuard::from($config['write_guard']),
            selfHosted: (bool) $config['self_hosted'],
        );
    }

    /**
     * A descriptor for a stored catalog entry.
     *
     * The provider's own model tag is the id. A stored entry carries no separate
     * identifier: one that an operator types is one an operator can mistype or
     * rename, and the tag is already the identity everywhere it is persisted
     * (`ai_credit_transactions.model`, `ModelRegistry::ratesFor()`). Retagging a
     * row is therefore a new model by construction, which is what it is.
     *
     * `selfHosted` is false by construction: self-hosted models are never stored,
     * they are merged from env by ModelRegistry, and they keep the env-derived ids
     * they have always had because no operator types those either.
     *
     * An unmeasured entry gets the weaker guard. Claiming `api` without proof
     * would tell the write path the provider refuses parallel tool calls when it
     * may not.
     */
    public static function fromCatalogEntry(CatalogEntry $entry): self
    {
        $measurement = $entry->measurement;

        return new self(
            id: $entry->model,
            label: $entry->label,
            provider: $entry->provider,
            model: $entry->model,
            minPlan: $entry->minPlan,
            creditMultiplier: $entry->creditMultiplier,
            supportsTools: $measurement instanceof Measurement && $measurement->supportsTools,
            writeGuard: $measurement instanceof Measurement ? $measurement->writeGuard : WriteGuard::Prompt,
            selfHosted: false,
        );
    }

    /**
     * Servable on this install: tool-capable, has a model tag, and its provider
     * connection is configured. Cloud providers need a key; self-hosted providers
     * need a base URL.
     */
    public function isAvailable(): bool
    {
        if (! $this->supportsTools || $this->model === null || $this->model === '') {
            return false;
        }

        /** @var array<string, mixed> $connection */
        $connection = config("ai.providers.{$this->provider}", []);

        return $this->selfHosted
            ? filled($connection['url'] ?? null)
            : filled($connection['key'] ?? null);
    }

    public function allowedForPlan(Plan $plan): bool
    {
        return $plan->rank() >= $this->minPlan->rank();
    }

    /**
     * Label shown in the picker. Self-hosted models surface their configured
     * model tag (e.g. "qwen3:8b") so operators recognise their own model; cloud
     * models use their curated label.
     */
    public function displayLabel(): string
    {
        return $this->selfHosted && $this->model !== null && $this->model !== ''
            ? $this->model
            : $this->label;
    }
}
