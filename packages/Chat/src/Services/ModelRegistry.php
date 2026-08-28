<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Enums\Plan;
use Relaticle\Chat\Support\ModelDescriptor;

final readonly class ModelRegistry
{
    /** @var list<ModelDescriptor> */
    private array $models;

    /** @var array<string, array{input_per_mtok: float, output_per_mtok: float}> */
    private array $rates;

    /** @var array<string, float> */
    private array $multipliers;

    public function __construct()
    {
        /** @var list<array<string, mixed>> $entries */
        $entries = config('chat.models', []);

        $custom = $this->customFromConfig();

        $enabled = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => ($entry['enabled'] ?? true) === true,
        ));

        $this->models = [
            ...array_map(ModelDescriptor::fromEntry(...), $enabled),
            ...array_map(ModelDescriptor::fromConfig(...), $custom),
        ];

        // Every entry, disabled ones included. A retired model still has to be priced
        // for the spend widget AND charged at the multiplier it was retired on: a turn
        // enqueued before the catalog changed settles after it, and reading this off
        // the picker instead would silently re-price that turn at 1x.
        $rates = [];
        $multipliers = [];

        foreach ([...$entries, ...$custom] as $entry) {
            $model = $entry['model'] ?? null;

            if (! is_string($model)) {
                continue;
            }

            $input = $entry['input_per_mtok'] ?? null;
            $output = $entry['output_per_mtok'] ?? null;

            if (is_numeric($input) && is_numeric($output)) {
                $rates[$model] = [
                    'input_per_mtok' => (float) $input,
                    'output_per_mtok' => (float) $output,
                ];
            }

            if (is_numeric($entry['credit_multiplier'] ?? null)) {
                $multipliers[$model] = (float) $entry['credit_multiplier'];
            }
        }

        $this->rates = $rates;
        $this->multipliers = $multipliers;
    }

    /** @return list<ModelDescriptor> */
    public function all(): array
    {
        return $this->models;
    }

    public function find(string $id): ?ModelDescriptor
    {
        foreach ($this->models as $model) {
            if ($model->id === $id) {
                return $model;
            }
        }

        return null;
    }

    /** @return list<ModelDescriptor> */
    public function available(): array
    {
        return array_values(array_filter(
            $this->models,
            static fn (ModelDescriptor $m): bool => $m->isAvailable(),
        ));
    }

    /**
     * What the product offers, whether or not this install holds the key.
     *
     * `available()` answers a different question — servable HERE — and the public
     * pricing and AI pages must not be answering that one. A rotated key is an
     * outage, not a change to what a plan includes, and routing marketing copy
     * through it renders "Every plan can use  and any self-hosted model you connect
     * yourself" with a hole where the name belongs. `supports_tools` still applies:
     * a model AiModelResolver::pick() can never select must never be advertised.
     *
     * @return list<ModelDescriptor>
     */
    public function offered(): array
    {
        return array_values(array_filter(
            $this->models,
            static fn (ModelDescriptor $m): bool => ! $m->selfHosted && $m->supportsTools,
        ));
    }

    /**
     * Voice input is servable on this install: the feature is switched on and
     * the transcription provider is configured. Same availability shape the
     * model picker uses for a cloud provider (ModelDescriptor::isAvailable()),
     * read off `ai.default_for_transcription` so this gate and the provider
     * PendingTranscriptionGeneration::generate() falls back to cannot disagree.
     */
    public function voiceInputAvailable(): bool
    {
        if (config('chat.voice_enabled') !== true) {
            return false;
        }

        $provider = config('ai.default_for_transcription');

        return is_string($provider) && filled(config("ai.providers.{$provider}.key"));
    }

    /** @return list<array{value:string,label:string,provider:?string,min_plan:string}> */
    public function pickerOptions(): array
    {
        $options = [[
            'value' => 'auto',
            'label' => __('Auto'),
            'provider' => null,
            'min_plan' => Plan::default()->value,
        ]];

        foreach ($this->available() as $model) {
            $options[] = [
                'value' => $model->id,
                'label' => $model->displayLabel(),
                'provider' => $model->provider,
                'min_plan' => $model->minPlan->value,
            ];
        }

        return $options;
    }

    /** @return list<string> */
    public function allowedIdsFor(Plan $plan): array
    {
        $ids = ['auto'];

        foreach ($this->available() as $model) {
            if ($model->allowedForPlan($plan)) {
                $ids[] = $model->id;
            }
        }

        return $ids;
    }

    /**
     * Auto's failover order: the entries flagged `auto`, in list order, then every
     * env-derived self-hosted model.
     *
     * Self-hosted models are appended rather than stored because they used to sit
     * at the tail of `chat.auto_chain`, and that tail is what makes Auto usable on
     * an install with no cloud keys at all.
     *
     * @return list<ModelDescriptor>
     */
    public function autoChain(): array
    {
        /** @var list<array<string, mixed>> $entries */
        $entries = config('chat.models', []);

        $chosen = array_filter(
            $entries,
            static fn (array $entry): bool => ($entry['auto'] ?? false) === true
                && ($entry['enabled'] ?? true) === true,
        );

        return [
            ...array_map(ModelDescriptor::fromEntry(...), array_values($chosen)),
            ...array_map(ModelDescriptor::fromConfig(...), $this->customFromConfig()),
        ];
    }

    /**
     * @return array{input_per_mtok: float, output_per_mtok: float}|null
     */
    public function ratesFor(string $model): ?array
    {
        return $this->rates[$model] ?? null;
    }

    public function multiplierFor(string $model): float
    {
        return $this->multipliers[$model] ?? 1.0;
    }

    /**
     * Self-hosted models, built from env on every read.
     *
     * They are deliberately not stored: `.env` stays their single source of truth,
     * so OLLAMA_MODEL and SELF_HOSTED_AI_MODELS keep taking effect without a deploy
     * and without anyone editing the panel. Ollama keeps the bare `ollama` id it has
     * always had, because that id is what a user's saved preference stores.
     *
     * @return list<array{id:string,label:string,provider:?string,model:?string,min_plan:string,credit_multiplier:int|float,supports_tools:bool,write_guard:string,self_hosted:bool}>
     */
    private function customFromConfig(): array
    {
        return [...$this->ollamaFromEnv(), ...$this->customEndpointFromEnv()];
    }

    /**
     * @return list<array{id:string,label:string,provider:?string,model:?string,min_plan:string,credit_multiplier:int|float,supports_tools:bool,write_guard:string,self_hosted:bool}>
     */
    private function ollamaFromEnv(): array
    {
        $model = config('chat.ollama.model');

        if (! is_string($model) || $model === '') {
            return [];
        }

        return [[
            'id' => 'ollama',
            'label' => 'Ollama',
            'provider' => 'ollama',
            'model' => $model,
            'min_plan' => Plan::Free->value,
            'credit_multiplier' => 1.0,
            'supports_tools' => true,
            'write_guard' => 'prompt',
            'self_hosted' => true,
        ]];
    }

    /**
     * @return list<array{id:string,label:string,provider:?string,model:?string,min_plan:string,credit_multiplier:int|float,supports_tools:bool,write_guard:string,self_hosted:bool}>
     */
    private function customEndpointFromEnv(): array
    {
        $url = config('chat.self_hosted.url');
        $models = config('chat.self_hosted.models');

        if (! is_string($url) || $url === '' || ! is_string($models) || $models === '') {
            return [];
        }

        $tags = array_values(array_filter(array_map(trim(...), explode(',', $models))));

        return array_map(static fn (string $tag): array => [
            'id' => "selfhosted:{$tag}",
            'label' => $tag,
            'provider' => 'selfhosted',
            'model' => $tag,
            'min_plan' => Plan::Free->value,
            'credit_multiplier' => 1.0,
            'supports_tools' => true,
            'write_guard' => 'prompt',
            'self_hosted' => true,
        ], $tags);
    }
}
