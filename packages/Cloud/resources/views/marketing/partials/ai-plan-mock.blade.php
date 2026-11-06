@php
    $icon = static fn (string $type): ?string => \Relaticle\Chat\Support\RecordChipRenderer::iconPath($type);

    $steps = [
        ['company', 'Kovra Systems', __('Create company'), [[__('Account Owner'), 'Lena Ortiz', false]]],
        ['people', 'Sarah Chen', __('Create person'), [[__('Company'), 'Kovra Systems', true], [__('Job Title'), __('VP of Engineering'), false]]],
        ['task', __('Call Sarah Chen'), __('Create task'), [[__('Linked people'), 'Sarah Chen', true], [__('Due Date'), today()->next('Friday')->isoFormat('ll'), false]]],
    ];
@endphp

<div class="bg-dots flex h-full min-h-[inherit] items-center justify-center p-4 sm:p-8 md:p-12">
    <x-cloud::marketing.chat-window class="w-full max-w-lg">
        <div class="space-y-3 p-4 sm:p-5">
            <p class="ml-auto w-fit max-w-[85%] rounded-2xl rounded-br-md bg-gray-100 px-4 py-2.5 text-sm leading-relaxed text-gray-900 dark:bg-white/10 dark:text-gray-100">
                {{ __('Add Kovra Systems, their VP of Engineering Sarah Chen, and a task to call her Friday.') }}
            </p>

            <p class="px-1 text-sm leading-relaxed text-gray-900 dark:text-gray-100">{{ __('Three changes. The person links to the new company, and the task to the new person.') }}</p>

            <div class="space-y-2">
                <div class="overflow-hidden rounded-xl border border-primary-200 bg-[var(--surface-block-bg)] ring-[3px] ring-primary-100 dark:border-primary-400/30 dark:ring-primary-400/10" data-ai-plan>
                    <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-2.5 dark:border-white/5">
                        <p class="min-w-0 flex-1 truncate text-sm font-semibold leading-5 text-gray-900 dark:text-white">{{ trans_choice(':count change|:count changes', count($steps), ['count' => count($steps)]) }}</p>
                        <span class="hidden shrink-0 text-micro text-gray-500 sm:inline dark:text-gray-400">{{ __('Hover a line to leave it out') }}</span>
                    </div>

                    <ol class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach($steps as [$type, $recordLabel, $verb, $fields])
                            <li class="pb-2.5" data-ai-plan-step>
                                <div class="flex items-start gap-2.5 px-4 pt-3">
                                    <span class="mt-px flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon($type) }}"/></svg>
                                    </span>
                                    <p class="min-w-0 flex-1 truncate text-sm leading-6">
                                        <span class="text-gray-500 dark:text-gray-400">{{ $verb }}</span>
                                        <span class="font-semibold text-gray-900 dark:text-white">{{ $recordLabel }}</span>
                                    </p>
                                    <x-cloud::home.hero-dock-field/>
                                </div>
                                <p class="flex flex-wrap items-baseline gap-x-2 gap-y-1 pe-3 ps-[3.125rem]">
                                    @foreach($fields as [$fieldLabel, $fieldValue, $isNew])
                                        <span class="inline-flex min-w-0 items-baseline gap-1.5">
                                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $fieldLabel }}</span>
                                            @if($isNew)
                                                <span class="inline-flex items-center gap-1 rounded-md bg-primary-50 px-1.5 text-xs font-medium leading-5 text-primary-700 dark:bg-primary-400/10 dark:text-primary-300">{{ $fieldValue }} <span class="font-normal opacity-80">{{ __('new') }}</span></span>
                                            @else
                                                <span class="text-sm text-gray-900 dark:text-white">{{ $fieldValue }}</span>
                                            @endif
                                        </span>
                                        @unless($loop->last)
                                            <span class="text-gray-300 dark:text-gray-600" aria-hidden="true">&middot;</span>
                                        @endunless
                                    @endforeach
                                </p>
                            </li>
                        @endforeach
                    </ol>

                    <div class="flex items-center justify-end gap-2 border-t border-gray-100 px-4 py-2 dark:border-white/5">
                        <span class="inline-flex h-7 items-center rounded-md px-2.5 text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Discard all') }}</span>
                        <x-cloud::marketing.rela-approve tabindex="-1" :label="__('Approve :count changes', ['count' => count($steps)])"/>
                    </div>
                </div>

                <x-cloud::marketing.rela-locked/>
            </div>
        </div>
    </x-cloud::marketing.chat-window>
</div>
