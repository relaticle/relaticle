@php
    $assistantName = (string) config('chat.assistant_name');

    $icon = static fn (string $type): ?string => \Relaticle\Chat\Support\RecordChipRenderer::iconPath($type);

    $steps = [
        ['mailbox', __('Mon 9:12'), __('Adds Lena Ortiz and Northwind after your first email.')],
        ['you', __('Tue 14:30'), __('Moves Northwind renewal to Negotiation on the board.')],
        ['claude', __('Wed 10:05'), __('Creates a follow-up task for Thursday from a chat in Claude.')],
        ['rela', __('Fri 16:48'), __('Marks the opportunity as won after you approve the change.')],
    ];
@endphp

<x-cloud::marketing.frame class="spotlight dark" data-story>
    <x-cloud::marketing.frame-header :label="__('One record')" :lead="__('Four hands, one opportunity.')">
        {{ __('Your inbox, you, your agent and :name all work on the same record.', ['name' => $assistantName]) }}
    </x-cloud::marketing.frame-header>

    <div class="bg-rail grid grid-cols-1 gap-px lg:grid-cols-12">
        <div class="bg-tint feat-card relative min-h-[22rem] overflow-hidden lg:col-span-7">

            <div class="flex min-h-[inherit] items-center justify-center p-6 md:p-12">
                <x-cloud::marketing.window class="w-full max-w-md">
                    <div class="flex min-w-0 items-center gap-2.5 px-4 py-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-amber-500 text-white" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon('opportunity') }}"/>
                            </svg>
                        </span>
                        <p class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-900 dark:text-white">{{ __('Northwind renewal') }}</p>
                        <span class="rounded-md bg-emerald-50 px-1.5 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">{{ __('Won') }}</span>
                    </div>

                    <dl class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-white/5 dark:border-white/5">
                        @foreach([
                            [__('Company'), 'company', 'Northwind'],
                            [__('Point of Contact'), 'people', 'Lena Ortiz'],
                            [__('Amount'), null, '$48,000'],
                            [__('Close Date'), null, __('Nov 6, 2026')],
                            [__('Stage'), null, __('Won')],
                        ] as [$field, $type, $value])
                            <div class="flex items-center gap-3 px-4 py-2.5 text-sm">
                                <dt class="w-28 shrink-0 text-gray-500 dark:text-gray-400">{{ $field }}</dt>
                                <dd class="font-medium text-gray-900 dark:text-white">
                                    @if($type)
                                        <span class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-1.5 py-0.5 text-xs text-gray-700 dark:bg-white/[0.06] dark:text-gray-200">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3 w-3 text-gray-400" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon($type) }}"/>
                                            </svg>
                                            {{ $value }}
                                        </span>
                                    @else
                                        {{ $value }}
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>

                    <div class="flex items-center gap-3 border-t border-gray-100 px-4 py-3 dark:border-white/5">
                        <span class="h-4 w-4 shrink-0 rounded-full border-2 border-gray-300 dark:border-white/20" aria-hidden="true"></span>
                        <p class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900 dark:text-white">{{ __('Follow up with Lena Ortiz') }}</p>
                        <span class="inline-flex items-center gap-1 rounded-md bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
                            <x-ri-calendar-line class="h-3 w-3"/>{{ __('Thursday') }}
                        </span>
                        <x-cloud::marketing.actor as="claude"/>
                    </div>
                </x-cloud::marketing.window>
            </div>
        </div>

        <ol class="bg-rail grid gap-px lg:col-span-5">
            @foreach($steps as [$actor, $time, $action])
                <li data-story-step class="bg-surface p-6">
                    <div class="flex items-center justify-between gap-3">
                        <x-cloud::marketing.actor :as="$actor"/>
                        <span class="type-label border-rail rounded-full border px-2 py-0.5 text-gray-400">{{ $time }}</span>
                    </div>
                    <p class="mt-4 text-sm leading-relaxed text-gray-300">{{ $action }}</p>
                    @if($actor === 'rela')
                        <p class="mt-3 inline-flex items-center gap-1.5 text-xs font-medium text-emerald-400">
                            <x-ri-checkbox-circle-fill class="h-4 w-4"/>
                            {{ __('Approved by you') }}
                        </p>
                    @endif
                </li>
            @endforeach
        </ol>
    </div>
</x-cloud::marketing.frame>
