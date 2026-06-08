<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use Illuminate\Database\Eloquent\Model;

final readonly class ChatContextService
{
    /**
     * @var array<string, array{type: string, class: class-string<Model>}>
     */
    private const array ENTITY_MAP = [
        'companies' => ['type' => 'company', 'class' => Company::class],
        'people' => ['type' => 'people', 'class' => People::class],
        'opportunities' => ['type' => 'opportunity', 'class' => Opportunity::class],
        'tasks' => ['type' => 'task', 'class' => Task::class],
        'notes' => ['type' => 'note', 'class' => Note::class],
    ];

    /**
     * @return array{page: string|null, record_type: string|null, record_id: string|null, record_name: string|null}
     */
    public function getContext(): array
    {
        $route = request()->route();
        $routeName = $route?->getName();
        $recordParam = $route?->parameter('record');

        $context = [
            'page' => $routeName,
            'record_type' => null,
            'record_id' => null,
            'record_name' => null,
        ];

        if (! is_string($recordParam) && ! is_object($recordParam)) {
            return $context;
        }

        if (! is_string($routeName)) {
            return $context;
        }

        foreach (self::ENTITY_MAP as $segment => $info) {
            if (! str_contains($routeName, ".{$segment}.")) {
                continue;
            }

            $recordId = is_object($recordParam)
                ? (string) (method_exists($recordParam, 'getKey') ? $recordParam->getKey() : '')
                : $recordParam;

            if ($recordId === '') {
                return $context;
            }

            /** @var class-string<Model> $modelClass */
            $modelClass = $info['class'];
            $model = $modelClass::query()->find($recordId);

            if ($model !== null) {
                $context['record_type'] = $info['type'];
                $context['record_id'] = (string) $model->getKey();
                $name = $model->getAttribute('name') ?? $model->getAttribute('title');
                $context['record_name'] = is_string($name) ? $name : null;
            }

            break;
        }

        return $context;
    }

    /**
     * @param  array{page: string|null, record_type: string|null, record_id: string|null, record_name: string|null}  $context
     * @return array<int, array{label: string, prompt: string}>
     */
    public function getSuggestedPrompts(array $context): array
    {
        $prompts = [
            ['label' => 'CRM overview', 'prompt' => 'Give me a summary of my CRM data'],
            ['label' => 'Overdue tasks', 'prompt' => 'Show my overdue tasks'],
            ['label' => 'Recent companies', 'prompt' => 'List companies added this week'],
            ['label' => 'Pipeline summary', 'prompt' => 'Show my opportunity pipeline summary'],
        ];

        if ($context['record_type'] === 'company' && $context['record_name']) {
            array_unshift($prompts,
                ['label' => "Summarize {$context['record_name']}", 'prompt' => "Summarize the company {$context['record_name']}"],
                ['label' => 'Find contacts', 'prompt' => "Find contacts at {$context['record_name']}"],
            );
        }

        if ($context['record_type'] === 'task') {
            array_unshift($prompts,
                ['label' => 'My tasks', 'prompt' => 'Show all my assigned tasks'],
            );
        }

        return array_slice($prompts, 0, 6);
    }
}
