<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\Task;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Js;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Relaticle\Flowforge\Contracts\HasBoard;

/**
 * Keeps the task open in a modal on the page in the address bar as `?task=<id>`.
 *
 * @mixin InteractsWithActions
 */
trait KeepsOpenTaskInUrl
{
    #[Url(as: 'task')]
    #[Locked]
    public ?string $openTaskId = null;

    protected bool $isOpeningTaskFromUrl = false;

    // Filament cannot mount an action during the first render, so the browser asks for it once loaded.
    #[Unexposed]
    public function mountKeepsOpenTaskInUrl(): void
    {
        $task = blank($this->openTaskId) ? null : Task::query()->withTrashed()->find($this->openTaskId);
        $modal = $task instanceof Task ? $this->taskModal($task) : null;

        if ($modal === null) {
            $this->openTaskId = null;

            return;
        }

        $this->isOpeningTaskFromUrl = true;
        $this->js(sprintf('$wire.mountAction(%s, %s, %s)', ...array_map(Js::from(...), $modal)));
    }

    #[Unexposed]
    public function dehydrateKeepsOpenTaskInUrl(): void
    {
        $action = $this->mountedActions[0] ?? null;

        // A legacy `?tableAction=` link, once opened, hands over to `?task=`. An empty array drops the key; null prints 'null'.
        if ($this->hasDefaultTableActionMounted) {
            $this->defaultTableAction = [];
            $this->defaultTableActionRecord = [];
        }

        $taskKey = $action === null ? null : $this->openTaskKey($action);

        if ($taskKey !== null) {
            $this->openTaskId = $taskKey;
        } elseif ($action === null && ! $this->isOpeningTaskFromUrl) {
            $this->openTaskId = null;
        }
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}|null
     */
    protected function taskModal(Task $task): ?array
    {
        $action = Gate::allows('update', $task) ? EditAction::getDefaultName() : ViewAction::getDefaultName();

        return [$action, [], ['recordKey' => $task->getKey(), ...($this instanceof HasBoard ? [] : ['table' => true])]];
    }

    /**
     * @param  array<string, mixed>  $action
     */
    protected function openTaskKey(array $action): ?string
    {
        $recordKey = $action['context']['recordKey'] ?? null;
        $opensTask = in_array($action['name'] ?? null, [EditAction::getDefaultName(), ViewAction::getDefaultName()], true);

        return $opensTask && is_string($recordKey) ? $recordKey : null;
    }
}
