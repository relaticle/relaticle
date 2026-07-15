<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Enums\Plan;
use Relaticle\Chat\Support\ModelDescriptor;

final readonly class ModelRegistry
{
    /** @var list<ModelDescriptor> */
    private array $models;

    public function __construct()
    {
        /** @var list<array{id:string,label:string,provider:?string,model:?string,min_plan:string,credit_multiplier:int|float,supports_tools:bool,write_guard:string,self_hosted:bool}> $curated */
        $curated = config('chat.models', []);

        $this->models = array_map(
            ModelDescriptor::fromConfig(...),
            [...$curated, ...$this->customFromConfig()],
        );
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

    /** @return list<ModelDescriptor> */
    public function autoChain(): array
    {
        /** @var list<string> $ids */
        $ids = config('chat.auto_chain', []);

        return array_values(array_filter(array_map(
            $this->find(...),
            $ids,
        )));
    }

    public function multiplierFor(string $modelId): float
    {
        foreach ($this->models as $model) {
            if ($model->model === $modelId) {
                return $model->creditMultiplier;
            }
        }

        return 1.0;
    }

    /** @return list<array{id:string,label:string,provider:?string,model:?string,min_plan:string,credit_multiplier:int|float,supports_tools:bool,write_guard:string,self_hosted:bool}> */
    private function customFromConfig(): array
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
