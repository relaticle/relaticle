<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Throwable;

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
     * Resolve CRM record context from an explicit URL.
     *
     * Takes a URL rather than reading the ambient request: this runs inside
     * Livewire XHRs, where request()->route() is Livewire's own update
     * endpoint and never the record page the user is looking at.
     *
     * The URL is client-supplied, so the resolved record is team-scoped and
     * policy-checked here. The send path re-validates independently.
     *
     * @return array{record_type: string|null, record_id: string|null, record_name: string|null}
     */
    public function getContextForUrl(string $url): array
    {
        $context = [
            'record_type' => null,
            'record_id' => null,
            'record_name' => null,
        ];

        /** @var User|null $user */
        $user = auth()->user();

        if (! $user instanceof User || $user->currentTeam === null) {
            return $context;
        }

        try {
            $request = Request::create($url);
            $route = Route::getRoutes()->match($request);
        } catch (Throwable) {
            return $context;
        }

        $routeName = $route->getName();

        if (! is_string($routeName)) {
            return $context;
        }

        foreach (self::ENTITY_MAP as $segment => $info) {
            if (! str_contains($routeName, ".{$segment}.")) {
                continue;
            }

            // Task and Note records open as Filament modals on index routes, so the id
            // rides in the query string rather than as a route parameter.
            $recordId = $this->extractRecordId($route->parameter('record'))
                ?? $this->extractRecordId($request->query('tableActionRecord'));

            if ($recordId === null) {
                return $context;
            }

            /** @var class-string<Model> $modelClass */
            $modelClass = $info['class'];

            $model = $modelClass::query()
                ->whereBelongsTo($user->currentTeam)
                ->whereKey($recordId)
                ->first();

            if (! $model instanceof Model || $user->cannot('view', $model)) {
                return $context;
            }

            $context['record_type'] = $info['type'];
            $context['record_id'] = (string) $model->getKey();
            $name = $model->getAttribute('name') ?? $model->getAttribute('title');
            $context['record_name'] = is_string($name) ? $name : null;

            break;
        }

        return $context;
    }

    private function extractRecordId(mixed $recordParam): ?string
    {
        return is_string($recordParam) && $recordParam !== '' ? $recordParam : null;
    }

    /**
     * @param  array{record_type: string|null, record_id: string|null, record_name: string|null}  $context
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

        $name = $context['record_name'];

        if (is_string($name) && $name !== '') {
            $recordPrompts = match ($context['record_type']) {
                'company' => [
                    ['label' => "Summarize {$name}", 'prompt' => "Summarize the company {$name}"],
                    ['label' => 'Find contacts', 'prompt' => "Find contacts at {$name}"],
                ],
                'people' => [
                    ['label' => "Summarize {$name}", 'prompt' => "Summarize the contact {$name}"],
                    ['label' => 'Recent activity', 'prompt' => "What has happened recently with {$name}?"],
                ],
                'opportunity' => [
                    ['label' => "Summarize {$name}", 'prompt' => "Summarize the opportunity {$name}"],
                    ['label' => 'Next steps', 'prompt' => "What are the next steps to move {$name} forward?"],
                ],
                default => [],
            };

            array_unshift($prompts, ...$recordPrompts);
        }

        if ($context['record_type'] === 'task') {
            array_unshift($prompts,
                ['label' => 'My tasks', 'prompt' => 'Show all my assigned tasks'],
            );
        }

        return array_slice($prompts, 0, 6);
    }
}
