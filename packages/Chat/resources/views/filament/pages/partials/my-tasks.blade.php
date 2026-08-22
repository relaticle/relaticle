@php
    /** @var \Illuminate\Support\Collection<int, \Relaticle\Chat\Data\MyTaskItem> $myTasks */
    $myTasks = $this->myTasks;
    $count = $myTasks->count();
    $canComplete = $this->canCompleteTasks;
@endphp

<div class="mt-14">
    <div class="mb-3 flex items-center justify-between">
        <h2 class="flex items-baseline gap-2 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
            <span>{{ __('filament/pages/dashboard.tasks.heading') }}</span>
            <span class="text-gray-400 dark:text-gray-500">{{ $count }}</span>
        </h2>
        <div class="flex items-center gap-3">
            <a
                href="{{ $this->getTasksIndexUrl() }}"
                class="rounded-sm text-xs text-gray-500 transition hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:text-gray-400 dark:hover:text-white"
            >
                {{ __('filament/pages/dashboard.tasks.view_all') }}
            </a>
            @if($count > 0)
                {{ $this->createTaskHeaderAction }}
            @endif
        </div>
    </div>

    @if($count === 0)
        <div class="rounded-xl border border-dashed border-[var(--surface-card-border)] bg-[var(--surface-card-bg)] px-6 py-10 text-center">
            <p class="text-sm font-medium text-gray-900 dark:text-white">
                {{ __('filament/pages/dashboard.tasks.empty.title') }}
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('filament/pages/dashboard.tasks.empty.description') }}
            </p>
            <div class="mt-4 flex justify-center">
                {{ $this->createTaskAction }}
            </div>
        </div>
    @else
        <ul class="divide-y divide-[var(--surface-card-border)] overflow-hidden rounded-xl border border-[var(--surface-card-border)] bg-[var(--surface-card-bg)]">
            @foreach($myTasks as $task)
                @php
                    $dateClass = match ($task->severity) {
                        'overdue', 'today' => 'text-red-600 dark:text-red-400',
                        default => 'text-gray-500 dark:text-gray-400',
                    };
                @endphp
                {{-- Completing a row is optimistic: the tick fills at once, the
                     row collapses, and the server write fires as it finishes. A
                     timer, not transitionend, so reduced motion still completes. --}}
                <li
                    wire:key="my-task-{{ $task->id }}"
                    data-testid="my-task-row"
                    data-severity="{{ $task->severity ?? 'none' }}"
                    x-data="{ checked: false }"
                    :class="checked ? 'grid-rows-[0fr] opacity-0' : 'grid-rows-[1fr] opacity-100'"
                    class="grid transition-all delay-150 duration-300 ease-out motion-reduce:transition-none"
                >
                    <div class="flex items-center gap-3 overflow-hidden pl-4 transition hover:bg-gray-50 dark:hover:bg-white/5">
                        @if($canComplete)
                            <button
                                type="button"
                                role="checkbox"
                                aria-checked="false"
                                :aria-checked="checked"
                                :disabled="checked"
                                x-on:click="checked = true; setTimeout(() => $wire.completeTask(@js($task->id)).catch(() => checked = false), 450)"
                                title="{{ __('filament/pages/dashboard.tasks.complete') }}"
                                aria-label="{{ __('filament/pages/dashboard.tasks.complete') }}"
                                class="flex size-5 flex-shrink-0 items-center justify-center rounded-full border-2 transition duration-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500"
                                :class="checked
                                    ? 'border-primary-600 bg-primary-600 dark:border-primary-500 dark:bg-primary-500'
                                    : 'border-gray-300 hover:border-primary-500 dark:border-gray-600 dark:hover:border-primary-400'"
                            >
                                <span
                                    class="transition duration-200 motion-reduce:transition-none"
                                    :class="checked ? 'scale-100 opacity-100' : 'scale-50 opacity-0'"
                                >
                                    <x-heroicon-m-check class="size-3.5 text-white" />
                                </span>
                            </button>
                        @endif
                        <a
                            href="{{ $task->editUrl }}"
                            class="flex flex-1 items-center gap-3 py-3 pr-4 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-500"
                        >
                            <span
                                class="flex-1 truncate text-sm transition duration-200 motion-reduce:transition-none"
                                :class="checked ? 'text-gray-400 line-through dark:text-gray-500' : 'text-gray-900 dark:text-white'"
                                title="{{ $task->title }}"
                            >{{ $task->title }}</span>
                            @if($task->dueAt)
                                <time datetime="{{ $task->dueAt->toDateString() }}" class="text-xs {{ $dateClass }}">
                                    {{ $task->dueAt->isoFormat('MMM D, YYYY') }}
                                </time>
                            @endif
                        </a>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
