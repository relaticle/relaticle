@php
    /** @var \Illuminate\Support\Collection<int, \Relaticle\Chat\Data\MyTaskItem> $myTasks */
    $myTasks = $this->myTasks;
    $count = $myTasks->count();
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
                class="text-xs text-gray-500 transition hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
            >
                {{ __('filament/pages/dashboard.tasks.view_all') }}
            </a>
            @if($count > 0)
                {{ $this->createTaskHeaderAction }}
            @endif
        </div>
    </div>

    @if($count === 0)
        <div class="rounded-xl border border-dashed border-gray-200 bg-white px-6 py-10 text-center dark:border-gray-700 dark:bg-gray-800">
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
        <ul class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 bg-white dark:divide-gray-700 dark:border-gray-700 dark:bg-gray-800">
            @foreach($myTasks as $task)
                @php
                    $dateClass = match ($task->severity) {
                        'overdue', 'today' => 'text-red-600 dark:text-red-400',
                        default => 'text-gray-500 dark:text-gray-400',
                    };
                @endphp
                <li data-testid="my-task-row" data-severity="{{ $task->severity ?? 'none' }}">
                    <a
                        href="{{ $task->editUrl }}"
                        class="flex items-center gap-3 px-4 py-3 transition hover:bg-gray-50 dark:hover:bg-gray-700/50"
                    >
                        <span aria-hidden="true" class="h-4 w-4 flex-shrink-0 rounded-full border border-gray-300 dark:border-gray-600"></span>
                        <span class="flex-1 truncate text-sm text-gray-900 dark:text-white">{{ $task->title }}</span>
                        @if($task->dueAt)
                            <span class="text-xs {{ $dateClass }}">
                                {{ $task->dueAt->isoFormat('MMM D, YYYY') }}
                            </span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
