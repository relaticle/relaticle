{{-- Static twin of the docked plan card (packages/Chat/resources/views/livewire/chat/proposal-card.blade.php and
     partials/_dock-step.blade.php). A batch step lists its records under one line, as the app renders a records[] call. --}}
@props([
    /** @var list<array{entity: string, verb?: string, name?: string, fields?: list<array{0: string, 1: string, 2?: string}>, title?: string, shared?: string, items?: list<array{name: string, fields: list<array{0: string, 1: string, 2?: string}>}>}> */
    'steps',
])

@php
    $count = collect($steps)->sum(fn (array $step): int => isset($step['items']) ? count($step['items']) : 1);
    $leaveOut = 'inline-flex min-h-8 min-w-8 shrink-0 items-center justify-center rounded-md text-gray-400 sm:opacity-0';
@endphp

<div {{ $attributes->class('overflow-hidden rounded-xl border border-primary-200 bg-[var(--surface-block-bg)] ring-[3px] ring-primary-100 dark:border-primary-400/30 dark:ring-primary-400/10') }} data-ai-plan>
    <div class="flex items-center gap-2.5 border-b border-gray-100 px-4 py-2.5 dark:border-white/5">
        <p class="min-w-0 flex-1 truncate text-sm font-semibold leading-5 text-gray-900 dark:text-white">{{ trans_choice(':count change|:count changes', $count, ['count' => $count]) }}</p>
        <span class="hidden shrink-0 text-micro text-gray-400 sm:inline dark:text-gray-500">{{ __('Hover a line to leave it out') }}</span>
    </div>

    <ol class="divide-y divide-gray-100 dark:divide-white/5">
        @foreach($steps as $step)
            @php($isBatch = isset($step['items']))
            <li class="group/line" data-ai-plan-step>
                <div class="flex items-start gap-2.5 px-4 pb-1.5 pt-3">
                    <span class="mt-px flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="{{ \Relaticle\Chat\Support\RecordChipRenderer::iconPath($step['entity']) }}"/></svg>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm leading-6">
                            @unless($isBatch)
                                <span class="text-gray-500 dark:text-gray-400">{{ $step['verb'] }}</span>
                            @endunless
                            <span class="font-semibold text-gray-900 dark:text-white">{{ $isBatch ? $step['title'] : $step['name'] }}</span>
                        </span>
                        @if($step['shared'] ?? false)
                            <span class="block text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $step['shared'] }}</span>
                        @endif
                    </span>
                    <span class="{{ $leaveOut }} sm:group-hover/line:opacity-100" aria-hidden="true">
                        <x-lucide-x class="h-4 w-4"/>
                    </span>
                </div>

                @if($isBatch)
                    <div class="divide-y divide-gray-100 pb-2 pe-2 ps-[3.125rem] dark:divide-white/5">
                        @foreach($step['items'] as $item)
                            <div class="group/row py-1.5">
                                <div class="flex items-center gap-2">
                                    <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900 dark:text-white">{{ $item['name'] }}</span>
                                    <span class="{{ $leaveOut }} sm:group-hover/row:opacity-100" aria-hidden="true">
                                        <x-lucide-x class="h-4 w-4"/>
                                    </span>
                                </div>
                                <x-cloud::marketing.app-plan-fields :fields="$item['fields']"/>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="pb-2.5 pe-3 ps-[3.125rem]">
                        <x-cloud::marketing.app-plan-fields :fields="$step['fields']"/>
                    </div>
                @endif
            </li>
        @endforeach
    </ol>

    <div class="flex items-center justify-end gap-2 border-t border-gray-100 px-4 py-2 dark:border-white/5" inert>
        <span class="inline-flex h-7 items-center rounded-md px-2.5 text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Discard all') }}</span>
        <x-cloud::marketing.rela-approve tabindex="-1" :label="trans_choice('Approve :count change|Approve :count changes', $count, ['count' => $count])"/>
    </div>
</div>
