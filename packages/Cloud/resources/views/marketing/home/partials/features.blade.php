@php
    $assistantName = (string) config('chat.assistant_name');
    $opportunityIcon = \Relaticle\Chat\Support\RecordChipRenderer::iconPath('opportunity');
    $quotes = collect(config('marketing.testimonials'))->keyBy(fn (array $t): string => $t['slot'] ?? '')->all();
@endphp

<x-cloud::marketing.frame id="features">
    <x-cloud::marketing.frame-header :label="__('Features')" :lead="__('One CRM, three ways to work.')">
        {{ __('Click, ask :name, or let your agent do it.', ['name' => $assistantName]) }}
    </x-cloud::marketing.frame-header>

    <div class="bg-rail grid gap-px">
        <x-cloud::marketing.showcase
            data-feature-row
            :label="__('01 · In the app')"
            :lead="__('A pipeline you read at a glance.')"
            :facts="[__('Board or list, your pick'), __('Drag a card to the next stage'), __('Rename, recolor or reorder the stages')]"
            :link-label="__('Track a deal through the pipeline')"
            :link-href="route('help.show', ['category' => 'getting-started', 'slug' => 'track-a-deal-through-the-pipeline'])"
            :quote="$quotes['board'] ?? null"
        >
            {{ __('Company, amount and close date, in the stages you define.') }}

            <x-slot:visual>
                <x-cloud::marketing.window class="absolute left-6 top-14 w-[74rem] md:left-10 md:top-14">
                    <x-cloud::marketing.theme-image
                        name="app-pipeline-preview"
                        :alt="__('Relaticle opportunities board grouped into pipeline stages, showing amount and close date')"
                        sizes="1440px"
                        class="-ml-[262px] block w-[1440px] max-w-none"
                    />
                </x-cloud::marketing.window>
            </x-slot:visual>
        </x-cloud::marketing.showcase>

        <x-cloud::marketing.showcase
            data-feature-row
            :label="__('02 · :name', ['name' => $assistantName])"
            :lead="__('Ask in plain words.')"
            :facts="[__('Type @ to point it at any record'), __('Every create, update and delete waits for your approval'), __('Fix a field on the card before you approve')]"
            :link-label="__('Meet :name', ['name' => $assistantName])"
            :link-href="route('ai')"
            :quote="$quotes['rela'] ?? null"
        >
            {{ __(':name drafts the change. You approve it before it is saved.', ['name' => $assistantName]) }}

            <x-slot:visual>
                <div class="flex min-h-[inherit] items-center justify-center p-6 md:p-12">
                    <div class="relative w-full max-w-md space-y-3" data-approval>
                        <div class="flex justify-end" data-approval-ask>
                            <p class="max-w-[85%] rounded-2xl rounded-br-md bg-gray-900 px-3.5 py-2.5 text-sm leading-relaxed text-white dark:bg-white/10 dark:text-gray-100">
                                {{ __('Mark the Northwind renewal as won and set the close date to today') }}
                            </p>
                        </div>

                        <x-cloud::marketing.window class="ring-[3px] ring-primary-100 dark:ring-primary-400/10" data-approval-card>
                            <div class="flex items-center justify-between gap-3 px-4 pt-3">
                                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Update Opportunity') }}</p>
                                <x-cloud::marketing.actor as="rela"/>
                            </div>

                            <div class="flex min-w-0 items-center gap-2.5 px-4 pb-3 pt-1.5">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-amber-500 text-white" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $opportunityIcon }}"/>
                                    </svg>
                                </span>
                                <p class="min-w-0 truncate text-sm font-semibold text-gray-900 dark:text-white">{{ __('Northwind renewal') }}</p>
                            </div>

                            <dl class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-white/5 dark:border-white/5">
                                @foreach([
                                    [__('Stage'), __('Negotiation'), __('Won')],
                                    [__('Close date'), __('Not set'), __('Today')],
                                ] as [$field, $before, $after])
                                    <div class="flex items-center gap-3 px-4 py-2.5 text-sm">
                                        <dt class="w-24 shrink-0 text-gray-500 dark:text-gray-400">{{ $field }}</dt>
                                        <dd class="flex min-w-0 flex-1 flex-wrap items-center gap-x-1.5">
                                            <span class="text-gray-400 line-through dark:text-gray-500">{{ $before }}</span>
                                            <x-ri-arrow-right-line class="h-3 w-3 shrink-0 text-gray-300 dark:text-gray-600"/>
                                            <span class="font-medium text-gray-900 dark:text-white">{{ $after }}</span>
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>

                            <div class="grid border-t border-gray-100 dark:border-white/5">
                                <div class="col-start-1 row-start-1 flex items-center justify-end gap-2 px-4 py-2.5" data-approval-pending>
                                    <span class="inline-flex h-7 items-center rounded-md px-2.5 text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Discard') }}</span>
                                    <span class="inline-flex h-7 items-center rounded-md bg-primary-600 px-2.5 text-xs font-medium text-white shadow-sm" data-approval-button>{{ __('Save changes') }}</span>
                                </div>
                                <div class="invisible col-start-1 row-start-1 flex items-center gap-1.5 px-4 py-2.5 text-xs font-medium text-emerald-700 opacity-0 dark:text-emerald-400" data-approval-done aria-hidden="true">
                                    <x-ri-checkbox-circle-fill class="h-4 w-4"/>
                                    {{ __('Approved by you. Saved to Northwind renewal.') }}
                                </div>
                            </div>
                        </x-cloud::marketing.window>

                        <svg data-approval-cursor class="pointer-events-none absolute left-0 top-0 h-5 w-5 opacity-0 drop-shadow" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M4 2.5l15 9.2-6.6 1.3 3.8 7.3-2.6 1.3-3.8-7.3L4 19z" fill="#111827" stroke="#fff" stroke-width="1.5" stroke-linejoin="round"/>
                        </svg>
                    </div>
                </div>
            </x-slot:visual>
        </x-cloud::marketing.showcase>

        <x-cloud::marketing.showcase
            data-feature-row
            :label="__('03 · Your agents')"
            :lead="__('Work from Claude or ChatGPT.')"
            :facts="[__('One consent screen, no API keys'), __('Acts as you, in the one workspace you approved'), __('Any MCP client with a personal access token')]"
            :link-label="__('Relaticle for Claude')"
            :link-href="route(\Relaticle\Cloud\Marketing\Enums\ConnectableAssistant::Claude->routeName())"
            :quote="$quotes['agents'] ?? null"
        >
            {{ __('It reads and updates the same records you do, with your permissions.') }}

            <x-slot:visual>
                <div class="flex min-h-[inherit] items-center justify-center p-4 sm:p-8 md:p-12">
                    <div class="relative w-full max-w-md" data-agent-demo>
                        <x-cloud::marketing.window>
                            <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-3 dark:border-white/[0.06]">
                                <x-ri-claude-fill class="h-4 w-4 text-anthropic"/>
                                <span class="text-sm font-medium text-gray-900 dark:text-white">Claude</span>
                                <span class="ml-auto inline-flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                    {{ __('Relaticle connected') }}
                                </span>
                            </div>
                            <div class="space-y-3 px-4 pb-10 pt-4">
                                <div class="flex justify-end">
                                    <p class="max-w-[85%] rounded-2xl rounded-br-md bg-gray-100 px-3.5 py-2.5 text-sm leading-relaxed text-gray-900 dark:bg-white/10 dark:text-gray-100">
                                        {{ __('Add a follow-up with Lena at Northwind for Thursday') }}
                                    </p>
                                </div>
                                <p class="inline-flex items-center gap-1.5 rounded-md border border-gray-200/80 bg-gray-50 px-2 py-1 text-xs text-gray-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-gray-400" data-agent-step>
                                    <x-ri-plug-line class="h-3.5 w-3.5 shrink-0 text-primary dark:text-primary-400"/>
                                    {{ __('Relaticle') }}
                                    <span aria-hidden="true">·</span>
                                    {{ __('Create task') }}
                                    <x-ri-check-line class="h-3.5 w-3.5 shrink-0 text-emerald-500"/>
                                </p>
                                <p class="text-sm leading-relaxed text-gray-700 dark:text-gray-300" data-agent-step>
                                    {{ __('Done. The task is due Thursday and linked to Lena Ortiz and the Northwind renewal.') }}
                                </p>
                            </div>
                        </x-cloud::marketing.window>

                        <x-cloud::marketing.window class="relative z-10 -mt-6 ml-auto w-[88%]" data-agent-task>
                            <div class="flex items-start gap-3 px-4 py-3.5">
                                <span class="mt-0.5 h-4 w-4 shrink-0 rounded-full border-2 border-gray-300 dark:border-white/20" aria-hidden="true"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ __('Follow up with Lena Ortiz') }}</p>
                                    <div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs">
                                        <span class="inline-flex items-center gap-1 rounded-md bg-amber-50 px-1.5 py-0.5 font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
                                            <x-ri-calendar-line class="h-3 w-3"/>{{ __('Thursday') }}
                                        </span>
                                        <span class="rounded-md bg-gray-100 px-1.5 py-0.5 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300">Lena Ortiz</span>
                                        <span class="rounded-md bg-gray-100 px-1.5 py-0.5 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300">{{ __('Northwind renewal') }}</span>
                                    </div>
                                </div>
                                <x-cloud::marketing.actor as="claude"/>
                            </div>
                        </x-cloud::marketing.window>
                    </div>
                </div>
            </x-slot:visual>
        </x-cloud::marketing.showcase>
    </div>
</x-cloud::marketing.frame>
