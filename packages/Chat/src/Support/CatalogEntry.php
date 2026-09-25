<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Enums\Plan;

/**
 * One row of the runtime-editable model catalog.
 *
 * The catalog is stored as plain arrays (spatie/laravel-settings owns the row's
 * JSON), and that shape used to be re-derived, with independently chosen
 * defaults, in eight places: ModelDescriptor, three passes inside ModelRegistry,
 * the panel's normalise / verify / badge paths, and the provider health check.
 * Those derivations drifted apart, and the drift shipped: the panel wrote back a
 * capabilities record it had read off the form, and models silently stopped
 * being served. This is the one place the array shape is understood.
 *
 * Identity is the provider's own model tag, because that is what is already
 * persisted everywhere it matters (`ai_credit_transactions.model`, a user's
 * saved preference, `?model=`). An entry therefore needs nothing but a tag to
 * exist: `provider` is nullable and `enabled` is free to be false, because a
 * retired row with neither still has to price the transactions that name it.
 * Servability is a separate question, and `isServable()` is where it is asked.
 */
final readonly class CatalogEntry
{
    public function __construct(
        public string $model,
        public string $label,
        public ?string $provider,
        public Plan $minPlan,
        public float $creditMultiplier,
        public ?float $inputPerMtok,
        public ?float $outputPerMtok,
        public bool $auto,
        public bool $enabled,
        public ?Measurement $measurement,
    ) {}

    /**
     * Null when the row names no model, which is not a model missing its tag: it
     * is not a model. Offering it would put an empty id in the picker and in
     * `Rule::in()`.
     *
     * Every scalar fails closed rather than throwing, because the settings row is
     * editable outside the panel: an unreadable plan becomes the most restrictive
     * one instead of handing an expensive model to every workspace, and a missing
     * multiplier becomes full price instead of `(float) null`, which is free.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function fromArray(array $entry): ?self
    {
        $model = $entry['model'] ?? null;

        if (! is_string($model) || $model === '') {
            return null;
        }

        $provider = $entry['provider'] ?? null;
        $label = $entry['label'] ?? null;

        return new self(
            model: $model,
            label: is_string($label) && $label !== '' ? $label : $model,
            provider: is_string($provider) && $provider !== '' ? $provider : null,
            minPlan: self::plan($entry['min_plan'] ?? null),
            creditMultiplier: is_numeric($entry['credit_multiplier'] ?? null) ? (float) $entry['credit_multiplier'] : 1.0,
            inputPerMtok: is_numeric($entry['input_per_mtok'] ?? null) ? (float) $entry['input_per_mtok'] : null,
            outputPerMtok: is_numeric($entry['output_per_mtok'] ?? null) ? (float) $entry['output_per_mtok'] : null,
            // Cast rather than compare: a Filament toggle hands back "1", and pint's
            // strict_comparison fixer rewrites any `==` put here.
            auto: (bool) ($entry['auto'] ?? false),
            enabled: (bool) ($entry['enabled'] ?? true),
            measurement: Measurement::fromEntry($entry),
        );
    }

    /**
     * The stored shape, key order included.
     *
     * The order is load-bearing: ManageAiSettings diffs the whole catalog with
     * `===` to decide whether a save changed anything, and a reordered array is
     * not identical to itself. Reordering here would make every no-op save write
     * an activity row.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'provider' => $this->provider,
            'model' => $this->model,
            'min_plan' => $this->minPlan->value,
            'credit_multiplier' => $this->creditMultiplier,
            'input_per_mtok' => $this->inputPerMtok,
            'output_per_mtok' => $this->outputPerMtok,
            'auto' => $this->auto,
            'enabled' => $this->enabled,
            'capabilities' => $this->measurement?->toCapabilities(),
            'verified_at' => $this->measurement?->verifiedAt,
        ];
    }

    public function withMeasurement(?Measurement $measurement): self
    {
        return new self(
            model: $this->model,
            label: $this->label,
            provider: $this->provider,
            minPlan: $this->minPlan,
            creditMultiplier: $this->creditMultiplier,
            inputPerMtok: $this->inputPerMtok,
            outputPerMtok: $this->outputPerMtok,
            auto: $this->auto,
            enabled: $this->enabled,
            measurement: $measurement,
        );
    }

    /**
     * Offered to users at all: switched on, named a provider, and measured as
     * able to call tools. A model AiModelResolver::pick() can never select must
     * never reach a picker, a plan gate or the public pricing copy.
     */
    public function isServable(): bool
    {
        return $this->enabled
            && $this->provider !== null
            && $this->measurement instanceof Measurement
            && $this->measurement->supportsTools;
    }

    /**
     * Whether this pairing is worth an API call on save. A disabled row is served
     * to nobody, and a row already carrying a measurement is the status quo.
     */
    public function needsProbe(): bool
    {
        return $this->enabled && ! $this->measurement instanceof Measurement;
    }

    public function pairing(): ?string
    {
        return $this->provider === null ? null : "{$this->provider}|{$this->model}";
    }

    /**
     * @return array{input_per_mtok: float, output_per_mtok: float}|null
     */
    public function rate(): ?array
    {
        if ($this->inputPerMtok === null || $this->outputPerMtok === null) {
            return null;
        }

        return [
            'input_per_mtok' => $this->inputPerMtok,
            'output_per_mtok' => $this->outputPerMtok,
        ];
    }

    private static function plan(mixed $plan): Plan
    {
        if ($plan instanceof Plan) {
            return $plan;
        }

        return Plan::tryFrom(is_string($plan) ? $plan : '') ?? Plan::Pro;
    }
}
