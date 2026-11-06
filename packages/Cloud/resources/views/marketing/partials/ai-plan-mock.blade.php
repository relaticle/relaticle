@php
    $icon = static fn (string $type): ?string => \Relaticle\Chat\Support\RecordChipRenderer::iconPath($type);

    $steps = [
        ['company', 'Kovra Systems', __('Create Company'), null, [[__('Account Owner'), 'Lena Ortiz']]],
        ['people', 'Sarah Chen', __('Create Person'), 1, [[__('Company'), __('Kovra Systems (step 1)')], [__('Job Title'), __('VP of Engineering')]]],
        ['task', __('Call Sarah Chen'), __('Create Task'), 2, [[__('Linked people'), __('Sarah Chen (step 2)')], [__('Due Date'), today()->next('Friday')->isoFormat('ll')]]],
    ];
@endphp

<div class="bg-dots flex h-full min-h-[inherit] items-center justify-center p-4 sm:p-8 md:p-12">
    <x-cloud::marketing.chat-window class="w-full max-w-lg">
        <div class="space-y-3 p-4 sm:p-5">
            <p class="ml-auto w-fit max-w-[85%] rounded-2xl rounded-br-md bg-gray-100 px-4 py-2.5 text-sm leading-relaxed text-gray-900 dark:bg-white/10 dark:text-gray-100">
                {{ __('Add Kovra Systems, their VP of Engineering Sarah Chen, and a task to call her Friday.') }}
            </p>

            <p class="px-1 text-sm leading-relaxed text-gray-900 dark:text-gray-100">{{ __('Review the plan below. The person links to the new company, and the task to the new person.') }}</p>

            <div class="overflow-hidden rounded-xl border border-primary-200 bg-[var(--surface-block-bg)] ring-[3px] ring-primary-100 dark:border-primary-400/30 dark:ring-primary-400/10" data-ai-plan>
                <div class="flex items-center gap-2.5 border-b border-gray-100 px-4 py-2.5 dark:border-white/5">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400" aria-hidden="true">
                        <x-heroicon-o-queue-list class="h-3.5 w-3.5"/>
                    </span>
                    <p class="min-w-0 flex-1 truncate text-sm font-semibold leading-5 text-gray-900 dark:text-white">{{ trans_choice(':count step|:count steps', count($steps), ['count' => count($steps)]) }}</p>
                    <span class="shrink-0 text-micro text-gray-500 dark:text-gray-400">{{ __('Approved together, in order') }}</span>
                </div>

                <ol class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach($steps as $position => [$type, $recordLabel, $stepTitle, $runsAfter, $fields])
                        <li class="group/step relative" data-ai-plan-step>
                            <span @class([
                                'absolute start-4 top-3.5 z-10 flex h-5 w-5 items-center justify-center rounded-full text-pico font-semibold tabular-nums ring-2 ring-white dark:ring-gray-900',
                                'bg-primary-600 text-white' => $position === 0,
                                'bg-gray-200 text-gray-600 dark:bg-white/10 dark:text-gray-300' => $position !== 0,
                            ]) aria-hidden="true">{{ $position + 1 }}</span>
                            <span class="absolute bottom-0 start-[1.6rem] top-8 w-px bg-gray-200 group-last/step:hidden dark:bg-white/10" aria-hidden="true"></span>

                            <div class="py-2.5 pe-3 ps-11">
                                <div class="flex min-w-0 items-center gap-2.5">
                                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon($type) }}"/></svg>
                                    </span>
                                    <span class="min-w-0 truncate text-sm font-semibold leading-5 text-gray-900 dark:text-white">{{ $recordLabel }}</span>
                                    <span class="hidden shrink-0 text-xs font-medium text-gray-500 sm:inline dark:text-gray-400">{{ $stepTitle }}</span>
                                </div>
                                @if($runsAfter)
                                    <p class="mt-0.5 text-micro text-gray-500 dark:text-gray-400">{{ __('Runs after step :step', ['step' => $runsAfter]) }}</p>
                                @endif
                            </div>

                            <dl class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-white/5 dark:border-white/5">
                                @foreach($fields as [$fieldLabel, $fieldValue])
                                    <div class="flex items-start gap-3 py-2 pe-3 ps-11 text-sm">
                                        <dt class="w-28 shrink-0 truncate leading-5 text-gray-700 sm:w-32 dark:text-gray-300">{{ $fieldLabel }}</dt>
                                        <dd class="min-w-0 flex-1 text-gray-900 dark:text-white">{{ $fieldValue }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </li>
                    @endforeach
                </ol>

                <div class="flex items-center justify-end gap-2 border-t border-gray-100 px-4 py-2 dark:border-white/5">
                    <span class="inline-flex h-7 items-center rounded-md px-2.5 text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Discard all') }}</span>
                    <span class="inline-flex h-7 items-center rounded-md bg-primary-600 px-2.5 text-xs font-medium text-white shadow-sm">{{ trans_choice('Approve :count step|Approve all :count', count($steps), ['count' => count($steps)]) }}</span>
                </div>
            </div>
        </div>
    </x-cloud::marketing.chat-window>
</div>
