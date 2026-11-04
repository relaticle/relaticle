@php
    $icons = [
        'opportunity' => \Relaticle\Chat\Support\RecordChipRenderer::iconPath('opportunity'),
        'people' => \Relaticle\Chat\Support\RecordChipRenderer::iconPath('people'),
        'task' => \Relaticle\Chat\Support\RecordChipRenderer::iconPath('task'),
        'company' => \Relaticle\Chat\Support\RecordChipRenderer::iconPath('company'),
    ];

    $scenarios = [
        'batch' => [__('Update three deals'), __('Move the three Kovra deals to Negotiation')],
        'create' => [__('Add a person'), __('Add Sarah Chen at Kovra Systems. She is VP of Engineering.')],
        'read' => [__('Find overdue tasks'), __('What is overdue this week?')],
    ];

    $deals = [
        ['name' => __('Kovra renewal'), 'from' => __('Qualification')],
        ['name' => __('Kovra add-on seats'), 'from' => __('Proposal')],
        ['name' => __('Kovra platform expansion'), 'from' => __('Qualification')],
    ];

    $tasks = [
        [__('Call Sarah Chen'), __('Aug 24, 2026'), 'Kovra Systems'],
        [__('Send proposal to Trellis Labs'), __('Aug 23, 2026'), 'Trellis Labs'],
        [__('Schedule demo with Kovra Systems'), __('Aug 22, 2026'), 'Kovra Systems'],
    ];

    $tab = 'inline-flex shrink-0 cursor-pointer items-center gap-2 rounded-full px-3.5 py-2 text-sm font-medium text-gray-600 ring-1 ring-gray-200 transition-colors hover:text-gray-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary aria-selected:bg-gray-950 aria-selected:text-white aria-selected:ring-gray-950 dark:text-gray-300 dark:ring-white/10 dark:hover:text-white dark:aria-selected:bg-white dark:aria-selected:text-gray-950 dark:aria-selected:ring-white';
    $panel = 'col-start-1 row-start-1 flex flex-col gap-4 transition duration-300 ease-out motion-reduce:transition-none';
    $bubble = 'ml-auto max-w-[85%] rounded-2xl rounded-br-md bg-gray-100 px-4 py-2.5 text-sm leading-relaxed text-gray-900 dark:bg-white/10 dark:text-gray-100';
    $reply = 'px-1 text-sm leading-relaxed text-gray-900 transition duration-300 ease-out motion-reduce:transition-none dark:text-gray-100';
    $tool = 'flex items-center gap-2 px-1 text-xs text-gray-600 transition-opacity duration-200 dark:text-gray-300';
    $card = 'overflow-hidden rounded-xl border border-primary-200 bg-[var(--surface-block-bg)] ring-[3px] ring-primary-100 transition duration-300 ease-out motion-reduce:transition-none dark:border-primary-400/30 dark:ring-primary-400/10';
    $tile = 'flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400';
    $footerButton = 'inline-flex h-7 cursor-pointer items-center rounded-md px-2.5 text-xs font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:cursor-not-allowed disabled:opacity-50';
    $discardButton = $footerButton.' text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white';
    $confirmButton = $footerButton.' bg-primary-600 text-white shadow-sm hover:bg-primary-500';
    $pageButton = 'inline-flex h-6 w-6 cursor-pointer items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 disabled:cursor-not-allowed disabled:opacity-40 dark:hover:bg-white/10 dark:hover:text-gray-200';
    $checkbox = 'mt-0.5 flex size-4 shrink-0 cursor-pointer items-center justify-center rounded border border-primary-600 bg-primary-600 text-white transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:cursor-default aria-[checked=false]:border-gray-300 aria-[checked=false]:bg-white aria-[checked=false]:text-transparent dark:aria-[checked=false]:border-white/20 dark:aria-[checked=false]:bg-white/5';
@endphp

<div
    id="demo"
    class="border-rail border-t"
    data-ai-playground
    x-data="{
        scenario: 'batch',
        order: @js(array_keys($scenarios)),
        thinking: false,
        timer: null,
        deals: @js(array_map(fn (array $deal): array => $deal + ['decision' => null], $deals)),
        cursor: 0,
        person: { decision: null, title: true, company: true },
        get undecided() {
            return this.deals.filter((deal) => deal.decision === null)
        },
        get activeDeal() {
            return this.undecided[Math.min(this.cursor, this.undecided.length - 1)] ?? null
        },
        get batchSummary() {
            const list = (names) => new Intl.ListFormat('en', { type: 'conjunction' }).format(names)
            const saved = this.deals.filter((deal) => deal.decision === 'saved').map((deal) => deal.name)
            const kept = this.deals.filter((deal) => deal.decision === 'discarded').map((deal) => deal.name)

            if (saved.length === 0) {
                return @js(__('Okay, I left all three deals as they were.'));
            }

            return @js(__('Done. Moved :deals to Negotiation.')).replace(':deals', list(saved))
                + (kept.length ? ' ' + @js(__('Left :deals as it was.')).replace(':deals', list(kept)) : '')
        },
        get personReply() {
            if (this.person.decision === 'discarded') {
                return @js(__('Okay, I did not add Sarah Chen.'));
            }

            return this.person.company
                ? @js(__('Sarah Chen has been created and linked to Kovra Systems.'))
                : @js(__('Sarah Chen has been created.'));
        },
        select(key) {
            if (key === this.scenario) {
                return
            }

            this.scenario = key
            this.reset()
        },
        move(step) {
            const next = (this.order.indexOf(this.scenario) + step + this.order.length) % this.order.length
            this.select(this.order[next])
            this.$nextTick(() => this.$refs['tab-' + this.order[next]].focus())
        },
        reset() {
            clearTimeout(this.timer)
            this.deals.forEach((deal) => deal.decision = null)
            this.cursor = 0
            this.person = { decision: null, title: true, company: true }
            this.thinking = ! window.matchMedia('(prefers-reduced-motion: reduce)').matches
            this.timer = setTimeout(() => this.thinking = false, 700)
        },
        decideDeal(decision) {
            this.activeDeal.decision = decision
            this.cursor = Math.min(this.cursor, Math.max(0, this.undecided.length - 1))
        },
    }"
>
    <div class="border-rail flex flex-col gap-4 border-b px-6 py-5 lg:flex-row lg:items-center lg:justify-between md:px-10">
        <div>
            <h2 class="type-label text-gray-500 dark:text-gray-400">{{ __('Try an ask') }}</h2>
            <p class="mt-1.5 text-sm text-gray-600 dark:text-gray-400">{{ __('Pick a request. Reads answer at once, and every change waits for you.') }}</p>
        </div>

        <div class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1 lg:pb-0" role="tablist" aria-label="{{ __('Example requests') }}" x-on:keydown.right.prevent="move(1)" x-on:keydown.left.prevent="move(-1)">
            @foreach($scenarios as $key => [$label, $ask])
                <button
                    type="button"
                    role="tab"
                    id="ai-ask-{{ $key }}"
                    aria-controls="ai-ask-panel"
                    aria-selected="{{ $key === 'batch' ? 'true' : 'false' }}"
                    tabindex="{{ $key === 'batch' ? '0' : '-1' }}"
                    x-ref="tab-{{ $key }}"
                    x-bind:aria-selected="(scenario === '{{ $key }}').toString()"
                    x-bind:tabindex="scenario === '{{ $key }}' ? 0 : -1"
                    x-on:click="select('{{ $key }}')"
                    class="{{ $tab }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="bg-tint flex justify-center px-4 py-10 sm:px-8 md:py-14">
        <x-cloud::marketing.window class="w-full max-w-2xl">
            <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-white/[0.06]">
                <x-cloud::marketing.actor as="rela"/>
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('Northwind workspace') }}</span>
            </div>

            <div id="ai-ask-panel" role="tabpanel" x-bind:aria-labelledby="'ai-ask-' + scenario" class="grid p-4 sm:p-6" aria-live="polite">

                {{-- Batch update: one record at a time, as a standalone batch card shows it. --}}
                <div class="{{ $panel }}" x-bind:class="scenario === 'batch' ? 'opacity-100' : 'invisible opacity-0'" data-ai-scenario="batch">
                    <p class="{{ $bubble }}">{{ $scenarios['batch'][1] }}</p>

                    <div class="grid">
                        <p class="{{ $tool }} col-start-1 row-start-1" x-bind:class="thinking ? 'opacity-100' : 'opacity-0'" aria-hidden="true">
                            <span class="size-1.5 rounded-full bg-gray-400 motion-safe:animate-pulse dark:bg-gray-500"></span>
                            {{ __('Preparing opportunity changes…') }}
                        </p>
                        <p class="{{ $reply }} col-start-1 row-start-1" x-bind:class="thinking ? 'opacity-0' : 'opacity-100'" x-text="undecided.length ? @js(__('Review the proposal below to update 3 opportunities.')) : batchSummary">{{ __('Review the proposal below to update 3 opportunities.') }}</p>
                    </div>

                    <div class="grid">
                        <div class="{{ $card }} col-start-1 row-start-1" x-bind:class="{ 'translate-y-2 opacity-0': thinking, 'invisible opacity-0': ! thinking && ! undecided.length }" data-ai-batch-card>
                            <div class="flex items-center gap-2 px-4 pt-3 text-xs font-medium text-gray-500 dark:text-gray-400">
                                <span class="min-w-0 flex-1 truncate">{{ __('Update Opportunity') }}</span>
                                <span class="tabular-nums text-gray-400 dark:text-gray-500" x-show="undecided.length > 1" x-text="undecided.length + ' ' + @js(__('records'))">3 {{ __('records') }}</span>
                            </div>

                            <div class="flex min-w-0 items-center gap-2.5 px-4 pb-2.5 pt-1.5">
                                <span class="{{ $tile }}" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons['opportunity'] }}"/></svg>
                                </span>
                                <p class="min-w-0 truncate text-sm font-semibold text-gray-900 dark:text-white" x-text="activeDeal?.name" data-ai-batch-record>{{ $deals[0]['name'] }}</p>
                            </div>

                            <div class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-white/5 dark:border-white/5">
                                <div class="flex items-center gap-3 px-4 py-2 text-xs font-medium text-gray-500 dark:text-gray-400" aria-hidden="true">
                                    <span class="w-24 shrink-0 sm:w-28">{{ __('Attribute') }}</span>
                                    <span>{{ __('New value') }}</span>
                                </div>
                                <div class="flex items-start gap-3 px-4 py-2.5 text-sm">
                                    <span class="w-24 shrink-0 leading-5 text-gray-700 sm:w-28 dark:text-gray-300">{{ __('Stage') }}</span>
                                    <span class="flex min-w-0 flex-1 flex-wrap items-center gap-x-1.5">
                                        <span class="text-gray-500 line-through decoration-gray-300 dark:text-gray-400 dark:decoration-gray-600" x-text="activeDeal?.from">{{ $deals[0]['from'] }}</span>
                                        <x-heroicon-m-arrow-right class="size-3 shrink-0 text-gray-400 dark:text-gray-500" aria-hidden="true"/>
                                        <span class="font-medium text-gray-900 dark:text-white">{{ __('Negotiation') }}</span>
                                    </span>
                                </div>
                            </div>

                            <div class="flex items-center gap-2 border-t border-gray-100 px-4 py-2 dark:border-white/5">
                                <div class="flex items-center gap-1 text-xs font-medium tabular-nums text-gray-500 dark:text-gray-400" x-show="undecided.length > 1">
                                    <button type="button" class="{{ $pageButton }}" x-on:click="cursor = Math.max(0, cursor - 1)" x-bind:disabled="cursor === 0" aria-label="{{ __('Previous record') }}">
                                        <x-heroicon-m-chevron-left class="h-3.5 w-3.5" aria-hidden="true"/>
                                    </button>
                                    <span class="select-none px-0.5" x-text="(cursor + 1) + '/' + undecided.length">1/3</span>
                                    <button type="button" class="{{ $pageButton }}" x-on:click="cursor = Math.min(undecided.length - 1, cursor + 1)" x-bind:disabled="cursor >= undecided.length - 1" aria-label="{{ __('Next record') }}">
                                        <x-heroicon-m-chevron-right class="h-3.5 w-3.5" aria-hidden="true"/>
                                    </button>
                                </div>

                                <div class="ms-auto flex items-center gap-2">
                                    <button type="button" class="{{ $discardButton }}" x-on:click="decideDeal('discarded')" x-bind:disabled="thinking">{{ __('Discard') }}</button>
                                    <button type="button" class="{{ $confirmButton }}" x-on:click="decideDeal('saved')" x-bind:disabled="thinking" data-ai-batch-save>{{ __('Save changes') }}</button>
                                </div>
                            </div>
                        </div>

                        <ul class="col-start-1 row-start-1 self-start overflow-hidden rounded-xl border border-[var(--surface-block-border)] bg-[var(--surface-block-bg)] transition duration-300 ease-out motion-reduce:transition-none" x-bind:class="undecided.length ? 'invisible opacity-0' : 'opacity-100'" data-ai-batch-decided>
                            @foreach($deals as $index => $deal)
                                <li class="flex items-center gap-2.5 border-b border-gray-100 px-4 py-2.5 last:border-b-0 dark:border-white/5">
                                    <span class="chat-chip min-w-0" data-record-type="opportunity">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons['opportunity'] }}"/></svg>
                                        <span class="chat-chip-label">{{ $deal['name'] }}</span>
                                    </span>
                                    <span class="ms-auto inline-flex shrink-0 items-center gap-1.5 text-micro font-medium text-gray-500 dark:text-gray-400">
                                        <span class="h-1.5 w-1.5 shrink-0 rounded-full" x-bind:class="deals[{{ $index }}].decision === 'saved' ? 'bg-green-500' : 'bg-gray-400'"></span>
                                        <span x-text="deals[{{ $index }}].decision === 'saved' ? @js(__('Updated')) : @js(__('Discarded'))">{{ __('Updated') }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>

                {{-- Create: the fields it will write, with the identity field locked. --}}
                <div class="{{ $panel }}" x-bind:class="scenario === 'create' ? 'opacity-100' : 'invisible opacity-0'" data-ai-scenario="create">
                    <p class="{{ $bubble }}">{{ $scenarios['create'][1] }}</p>

                    <div class="grid">
                        <p class="{{ $tool }} col-start-1 row-start-1" x-bind:class="thinking ? 'opacity-100' : 'opacity-0'" aria-hidden="true">
                            <span class="size-1.5 rounded-full bg-gray-400 motion-safe:animate-pulse dark:bg-gray-500"></span>
                            {{ __('Drafting a person…') }}
                        </p>
                        <p class="{{ $reply }} col-start-1 row-start-1" x-bind:class="thinking ? 'opacity-0' : 'opacity-100'" x-text="person.decision ? personReply : @js(__('Review the proposal below to add her to Kovra Systems.'))">{{ __('Review the proposal below to add her to Kovra Systems.') }}</p>
                    </div>

                    <div class="{{ $card }}" x-bind:class="{ 'translate-y-2 opacity-0': thinking }">
                        <div class="px-4 pt-3 text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Create Person') }}</div>

                        <div class="flex min-w-0 items-center gap-2.5 px-4 pb-2.5 pt-1.5">
                            <span class="{{ $tile }}" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons['people'] }}"/></svg>
                            </span>
                            <p class="min-w-0 truncate text-sm font-semibold text-gray-900 dark:text-white">Sarah Chen</p>
                        </div>

                        <div class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-white/5 dark:border-white/5">
                            <div class="flex items-center gap-3 px-4 py-2 text-xs font-medium text-gray-500 dark:text-gray-400" aria-hidden="true">
                                <span class="size-4 shrink-0"></span>
                                <span class="w-24 shrink-0 sm:w-28">{{ __('Attribute') }}</span>
                                <span>{{ __('New value') }}</span>
                            </div>
                            <div class="flex items-start gap-3 px-4 py-2.5 text-sm">
                                <x-cloud::home.hero-dock-checkbox class="mt-0.5" :locked="true"/>
                                <span class="w-24 shrink-0 leading-5 text-gray-700 sm:w-28 dark:text-gray-300">{{ __('Name') }}</span>
                                <span class="min-w-0 flex-1 font-medium text-gray-900 dark:text-white">Sarah Chen</span>
                            </div>
                            @foreach([['title', __('Job title'), __('VP of Engineering')], ['company', __('Company'), 'Kovra Systems']] as [$key, $field, $value])
                                <div class="flex items-start gap-3 px-4 py-2.5 text-sm">
                                    <button type="button" role="checkbox" class="{{ $checkbox }}" aria-checked="true" x-bind:aria-checked="person.{{ $key }}.toString()" x-bind:disabled="person.decision !== null" x-on:click="person.{{ $key }} = ! person.{{ $key }}" aria-label="{{ __('Include :field', ['field' => $field]) }}">
                                        <x-heroicon-m-check class="size-3" aria-hidden="true"/>
                                    </button>
                                    <span class="w-24 shrink-0 leading-5 text-gray-700 sm:w-28 dark:text-gray-300" x-bind:class="{ 'opacity-50': ! person.{{ $key }} }">{{ $field }}</span>
                                    <span class="min-w-0 flex-1 font-medium text-gray-900 dark:text-white" x-bind:class="{ 'opacity-50': ! person.{{ $key }} }">{{ $value }}</span>
                                </div>
                            @endforeach
                        </div>

                        <div class="grid border-t border-gray-100 dark:border-white/5">
                            <div class="col-start-1 row-start-1 flex items-center justify-end gap-2 px-4 py-2" x-bind:class="{ 'invisible': person.decision !== null }">
                                <button type="button" class="{{ $discardButton }}" x-on:click="person.decision = 'discarded'" x-bind:disabled="thinking">{{ __('Discard') }}</button>
                                <button type="button" class="{{ $confirmButton }}" x-on:click="person.decision = 'created'" x-bind:disabled="thinking" data-ai-create-confirm>{{ __('Create') }}</button>
                            </div>
                            <p class="invisible col-start-1 row-start-1 flex items-center gap-1.5 px-4 py-2.5 text-xs font-medium text-emerald-700 dark:text-emerald-400" x-bind:class="{ 'invisible': person.decision !== 'created' }" data-ai-create-done>
                                <x-ri-checkbox-circle-fill class="size-4" aria-hidden="true"/>
                                {{ __('Approved by you. Sarah Chen was created.') }}
                            </p>
                            <p class="invisible col-start-1 row-start-1 flex items-center gap-1.5 px-4 py-2.5 text-xs font-medium text-gray-600 dark:text-gray-300" x-bind:class="{ 'invisible': person.decision !== 'discarded' }">
                                <x-ri-close-circle-line class="size-4" aria-hidden="true"/>
                                {{ __('Discarded. Nothing was written.') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Read: answered at once, nothing to approve. --}}
                <div class="{{ $panel }}" x-bind:class="scenario === 'read' ? 'opacity-100' : 'invisible opacity-0'" data-ai-scenario="read">
                    <p class="{{ $bubble }}">{{ $scenarios['read'][1] }}</p>

                    <div class="grid">
                        <p class="{{ $tool }} col-start-1 row-start-1" x-bind:class="thinking ? 'opacity-100' : 'opacity-0'" aria-hidden="true">
                            <span class="size-1.5 rounded-full bg-gray-400 motion-safe:animate-pulse dark:bg-gray-500"></span>
                            {{ __('Searching tasks…') }}
                        </p>
                        <p class="{{ $reply }} col-start-1 row-start-1" x-bind:class="thinking ? 'opacity-0' : 'opacity-100'">{{ __('You have 3 overdue tasks, all past due this week. Reading never needs your approval.') }}</p>
                    </div>

                    <div class="overflow-hidden rounded-xl border border-[var(--surface-block-border)] bg-[var(--surface-block-bg)] transition duration-300 ease-out motion-reduce:transition-none" x-bind:class="{ 'translate-y-2 opacity-0': thinking }">
                        <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-2.5 dark:border-white/5">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons['task'] }}"/></svg>
                            </span>
                            <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Tasks') }}</span>
                        </div>
                        <ul class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach($tasks as [$taskTitle, $due, $company])
                                <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5 text-sm">
                                    <span class="chat-chip min-w-0" data-record-type="task">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons['task'] }}"/></svg>
                                        <span class="chat-chip-label">{{ $taskTitle }}</span>
                                    </span>
                                    <span class="text-red-600 dark:text-red-400">{{ $due }}</span>
                                    <span class="chat-chip ms-auto hidden sm:inline-flex" data-record-type="company">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons['company'] }}"/></svg>
                                        <span class="chat-chip-label">{{ $company }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between gap-3 border-t border-gray-100 px-4 py-2.5 text-xs text-gray-500 dark:border-white/[0.06] dark:text-gray-400">
                <span>{{ __('A demo with sample records. Nothing here touches a real workspace.') }}</span>
                <button type="button" class="shrink-0 cursor-pointer whitespace-nowrap font-medium text-gray-950 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-950 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white" x-on:click="reset()">{{ __('Start over') }}</button>
            </div>
        </x-cloud::marketing.window>
    </div>
</div>
