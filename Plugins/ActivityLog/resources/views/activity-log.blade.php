@php
    use Illuminate\Support\Str;
    use Relaticle\ActivityLog\Filament\Livewire\ActivityLogLivewire;
    use Relaticle\ActivityLog\Support\AttributeFormatter;

    $groups = $activities->groupBy(fn ($activity) => $activity->created_at->copy()->startOfDay()->toDateString());
@endphp

<div class="fi-activity-log flex flex-col gap-6">
    <div class="flex flex-wrap items-center gap-1 rounded-lg bg-gray-50 p-1 dark:bg-white/5">
        @foreach (ActivityLogLivewire::FILTERS as $tab)
            @php
                $isActive = $filter === $tab;
                $count = $counts[$tab] ?? 0;
            @endphp
            <button
                type="button"
                wire:click="setFilter('{{ $tab }}')"
                @class([
                    'flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium transition',
                    'bg-white text-gray-900 shadow-sm dark:bg-white/10 dark:text-white' => $isActive,
                    'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white' => ! $isActive,
                ])
            >
                <span>{{ Str::ucfirst($tab) }}</span>
                <span @class([
                    'rounded-full px-1.5 py-0.5 text-xs tabular-nums',
                    'bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-300' => $isActive,
                    'bg-gray-200 text-gray-700 dark:bg-white/10 dark:text-gray-300' => ! $isActive,
                ])>
                    {{ $count }}
                </span>
            </button>
        @endforeach
    </div>

    @if ($activities->isEmpty())
        <div class="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-gray-200 py-12 text-center dark:border-white/10">
            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-white/5 dark:text-gray-500">
                <x-filament::icon icon="heroicon-o-clock" class="h-5 w-5" />
            </div>
            <div class="space-y-1">
                <p class="text-sm font-medium text-gray-900 dark:text-white">No activity yet</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">Changes to this record will appear here.</p>
            </div>
        </div>
    @else
        <div class="flex flex-col gap-6">
            @foreach ($groups as $date => $items)
                @php
                    $groupDate = $items->first()->created_at;
                    $label = match (true) {
                        $groupDate->isToday() => 'Today',
                        $groupDate->isYesterday() => 'Yesterday',
                        $groupDate->isCurrentYear() => $groupDate->format('F j'),
                        default => $groupDate->format('F j, Y'),
                    };
                @endphp
                <section>
                    <header class="mb-3 flex items-center gap-3">
                        <h3 class="text-[11px] font-semibold uppercase tracking-[0.12em] text-gray-500 dark:text-gray-400">
                            {{ $label }}
                        </h3>
                        <span class="h-px flex-1 bg-gradient-to-r from-gray-200 to-transparent dark:from-white/10"></span>
                    </header>

                    <ol class="relative flex flex-col gap-3 pl-8 before:absolute before:inset-y-2 before:left-[11px] before:w-px before:bg-gray-200 dark:before:bg-white/10">
                        @foreach ($items as $activity)
                            @php
                                $event = $activity->event;
                                $dotClasses = match ($event) {
                                    'created' => 'bg-success-500 ring-success-500/20',
                                    'updated' => 'bg-primary-500 ring-primary-500/20',
                                    'deleted' => 'bg-danger-500 ring-danger-500/20',
                                    'restored' => 'bg-warning-500 ring-warning-500/20',
                                    default => 'bg-gray-400 ring-gray-400/20',
                                };
                                $iconColor = match ($event) {
                                    'created' => 'success',
                                    'updated' => 'primary',
                                    'deleted' => 'danger',
                                    'restored' => 'warning',
                                    default => 'gray',
                                };
                                $iconName = match ($event) {
                                    'created' => 'heroicon-o-plus-circle',
                                    'updated' => 'heroicon-o-pencil-square',
                                    'deleted' => 'heroicon-o-trash',
                                    'restored' => 'heroicon-o-arrow-uturn-left',
                                    default => 'heroicon-o-clock',
                                };

                                $changes = $activity->attribute_changes?->toArray() ?? [];
                                $newAttributes = $changes['attributes'] ?? [];
                                $oldAttributes = $changes['old'] ?? [];
                                $changeKeys = array_values(array_unique(array_merge(array_keys($newAttributes), array_keys($oldAttributes))));
                                $hasChanges = ! empty($changeKeys);

                                $causerName = $activity->causer?->name ?? 'System';
                                $verb = match ($event) {
                                    'created' => 'created this record',
                                    'updated' => 'updated '.count($changeKeys).' '.Str::plural('field', count($changeKeys)),
                                    'deleted' => 'deleted this record',
                                    'restored' => 'restored this record',
                                    default => $activity->description,
                                };
                            @endphp

                            <li class="relative">
                                <span class="pointer-events-none absolute -left-[29px] top-4 z-10 flex h-[22px] w-[22px] items-center justify-center rounded-full bg-white dark:bg-gray-900">
                                    <span @class(['h-2.5 w-2.5 rounded-full ring-4', $dotClasses])></span>
                                </span>

                                <x-filament::section
                                    compact
                                    :collapsible="$hasChanges"
                                    collapsed
                                    :icon="$iconName"
                                    :icon-color="$iconColor"
                                    icon-size="sm"
                                >
                                    <x-slot name="heading">
                                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $causerName }}</span>
                                        <span class="text-sm font-normal text-gray-500 dark:text-gray-400">{{ $verb }}</span>
                                    </x-slot>

                                    <x-slot name="description">
                                        <span
                                            class="tabular-nums"
                                            title="{{ $activity->created_at->toDayDateTimeString() }}"
                                        >
                                            {{ $activity->created_at->diffForHumans() }}
                                            &middot;
                                            {{ $activity->created_at->format('g:i A') }}
                                        </span>
                                    </x-slot>

                                    @if ($hasChanges)
                                        <dl class="divide-y divide-gray-100 text-sm dark:divide-white/5">
                                            @foreach ($changeKeys as $key)
                                                @php
                                                    $hasOld = array_key_exists($key, $oldAttributes);
                                                    $hasNew = array_key_exists($key, $newAttributes);
                                                @endphp
                                                <div class="grid grid-cols-[minmax(0,9rem)_minmax(0,1fr)] gap-x-4 py-2 first:pt-0 last:pb-0">
                                                    <dt class="truncate pt-[2px] text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                                        {{ Str::headline($key) }}
                                                    </dt>
                                                    <dd class="flex flex-col gap-1 text-[13px] leading-5">
                                                        @if ($hasOld)
                                                            <span class="flex items-start gap-2">
                                                                <span class="mt-[1px] select-none font-mono text-xs text-danger-500/70 dark:text-danger-400/70">&minus;</span>
                                                                <span class="break-all text-danger-700 line-through decoration-danger-500/40 dark:text-danger-300">{{ AttributeFormatter::format($oldAttributes[$key]) }}</span>
                                                            </span>
                                                        @endif
                                                        @if ($hasNew)
                                                            <span class="flex items-start gap-2">
                                                                <span class="mt-[1px] select-none font-mono text-xs text-success-600/80 dark:text-success-400/70">&plus;</span>
                                                                <span class="break-all text-success-700 dark:text-success-300">{{ AttributeFormatter::format($newAttributes[$key]) }}</span>
                                                            </span>
                                                        @endif
                                                    </dd>
                                                </div>
                                            @endforeach
                                        </dl>
                                    @endif
                                </x-filament::section>
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endforeach
        </div>

        @if ($hasMore)
            @if ($infiniteScroll)
                <div
                    wire:intersect="loadMore"
                    wire:key="activity-log-load-more"
                    class="flex items-center justify-center py-4 text-xs text-gray-500 dark:text-gray-400"
                >
                    <span wire:loading.remove wire:target="loadMore">Scroll to load more&hellip;</span>
                    <span wire:loading wire:target="loadMore" class="flex items-center gap-2">
                        <x-filament::loading-indicator class="h-4 w-4" />
                        Loading earlier activity&hellip;
                    </span>
                </div>
            @else
                <div class="flex justify-center">
                    <x-filament::button
                        color="gray"
                        outlined
                        size="sm"
                        wire:click="loadMore"
                        wire:loading.attr="disabled"
                        wire:target="loadMore"
                        icon="heroicon-m-arrow-down"
                    >
                        <span wire:loading.remove wire:target="loadMore">Load more</span>
                        <span wire:loading wire:target="loadMore">Loading&hellip;</span>
                    </x-filament::button>
                </div>
            @endif
        @endif
    @endif
</div>
