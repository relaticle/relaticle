@php
    $icons = [
        'opportunity' => \Relaticle\Chat\Support\RecordChipRenderer::iconPath('opportunity'),
        'people' => \Relaticle\Chat\Support\RecordChipRenderer::iconPath('people'),
        'task' => \Relaticle\Chat\Support\RecordChipRenderer::iconPath('task'),
        'company' => \Relaticle\Chat\Support\RecordChipRenderer::iconPath('company'),
    ];

    $scenarios = [
        'batch' => [__('Update three opportunities'), __('Move the three Kovra deals to Negotiation')],
        'create' => [__('Add a person'), __('Add Sarah Chen at Kovra Systems. She is VP of Engineering.')],
        'read' => [__('Find overdue tasks'), __('What is overdue?')],
    ];

    $pages = [
        'batch' => [__('Opportunities'), __('New opportunity'), 'opportunity'],
        'create' => [__('People'), __('New person'), 'people'],
        'read' => [__('Tasks'), __('New task'), 'task'],
    ];

    $deals = [
        ['name' => __('Kovra renewal'), 'from' => __('Qualification'), 'amount' => '$24,000'],
        ['name' => __('Kovra add-on seats'), 'from' => __('Proposal/Price Quote'), 'amount' => '$8,500'],
        ['name' => __('Kovra platform expansion'), 'from' => __('Qualification'), 'amount' => '$62,000'],
    ];

    $opportunityRows = [
        ['deal' => 0],
        ['name' => 'Trellis Labs pilot', 'company' => 'Trellis Labs', 'stage' => __('Negotiation/Review'), 'amount' => '$12,000'],
        ['deal' => 1],
        ['name' => 'Lumen Health onboarding', 'company' => 'Lumen Health', 'stage' => __('Proposal/Price Quote'), 'amount' => '$18,000'],
        ['deal' => 2],
        ['name' => 'Northwind renewal', 'company' => 'Northwind', 'stage' => __('Closed Won'), 'amount' => '$48,000'],
        ['name' => 'Halcyon Freight rollout', 'company' => 'Halcyon Freight', 'stage' => __('Qualification'), 'amount' => '$31,000'],
        ['name' => 'Brightline analytics seats', 'company' => 'Brightline', 'stage' => __('Proposal/Price Quote'), 'amount' => '$9,600'],
    ];

    $people = [
        ['Lena Ortiz', 'Northwind', __('Head of Operations')],
        ['Marcus Webb', 'Trellis Labs', __('CTO')],
        ['Priya Nair', 'Lumen Health', __('Procurement Lead')],
        ['Tomás Reyes', 'Halcyon Freight', __('Head of Logistics')],
        ['Emma Lindqvist', 'Brightline', __('Revenue Operations')],
        ['Daniel Okafor', 'Kovra Systems', __('Engineering Manager')],
    ];

    $tasks = [
        [__('Call Sarah Chen'), now()->subDay(), 'Kovra Systems'],
        [__('Send proposal to Trellis Labs'), now()->subDays(2), 'Trellis Labs'],
        [__('Schedule demo with Kovra Systems'), now()->subDays(4), 'Kovra Systems'],
        [__('Send renewal terms to Northwind'), now()->addDays(3), 'Northwind'],
        [__('Prepare Halcyon Freight kickoff'), now()->addDays(5), 'Halcyon Freight'],
        [__('Review Brightline security questionnaire'), now()->addDays(6), 'Brightline'],
        [__('Follow up with Lena Ortiz'), now()->addDays(8), 'Northwind'],
    ];

    $approvalRule = "Every write needs the user's approval.";

    $batchReplies = [
        __('Okay, I left all :count opportunities as they were.', ['count' => count($deals)]),
        ...array_map(
            fn (int $saved): string => $saved === count($deals)
                ? __('Done. Moved all :count opportunities to Negotiation/Review.', ['count' => $saved])
                : __('Done. Moved :saved of :count opportunities to Negotiation/Review.', ['saved' => $saved, 'count' => count($deals)]),
            range(1, count($deals)),
        ),
    ];


    $batchPrompt = __('Three Kovra opportunities will move to Negotiation/Review.');

    $personReplies = [
        'prompt' => __('I will add Sarah Chen at Kovra Systems as VP of Engineering.'),
        'linked' => __('Sarah Chen has been created at Kovra Systems.'),
        'created' => __('Sarah Chen has been created.'),
        'discarded' => __('Okay, I did not add Sarah Chen.'),
    ];

    $fade = 'transition duration-300 ease-out motion-reduce:transition-none';
    $fadeOpacity = 'transition-opacity duration-300 ease-out motion-reduce:transition-none';
    $ghost = 'invisible col-start-1 row-start-1';

    $chip = 'inline-flex shrink-0 cursor-pointer items-center rounded-full px-3 py-1.5 text-xs font-medium text-gray-600 ring-1 ring-gray-200 transition-colors hover:text-gray-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary aria-selected:bg-gray-950 aria-selected:text-white aria-selected:ring-gray-950 dark:text-gray-300 dark:ring-white/10 dark:hover:text-white dark:aria-selected:bg-white dark:aria-selected:text-gray-950 dark:aria-selected:ring-white';
    $panel = 'col-start-1 row-start-1 flex flex-col gap-4';
    $page = 'col-start-1 row-start-1 flex min-w-0 flex-col';
    $row = 'relative grid items-center gap-3 border-b border-gray-100 px-4 py-2.5 text-sm last:border-b-0 dark:border-white/5';
    $head = 'grid gap-3 border-b border-gray-100 px-4 py-2 text-xs font-medium text-gray-500 dark:border-white/5 dark:text-gray-400';
    $flash = 'pointer-events-none absolute inset-0 bg-primary-500/10 transition-opacity duration-[450ms] ease-out motion-reduce:transition-none dark:bg-primary-400/15';
    $bubble = 'ml-auto max-w-[85%] rounded-2xl rounded-br-md bg-gray-100 px-4 py-2.5 text-sm leading-relaxed text-gray-900 dark:bg-white/10 dark:text-gray-100';
    $reply = 'px-1 text-sm leading-relaxed text-gray-900 dark:text-gray-100';
    $tool = 'flex items-center gap-2 px-1 text-xs text-gray-600 dark:text-gray-300';
    $card = 'overflow-hidden rounded-xl border border-primary-200 bg-[var(--surface-block-bg)] ring-[3px] ring-primary-100 dark:border-primary-400/30 dark:ring-primary-400/10';
    $tile = 'flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400';
    $footerButton = 'inline-flex h-7 cursor-pointer items-center rounded-md px-2.5 text-xs font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:cursor-not-allowed disabled:opacity-50';
    $discardButton = $footerButton.' text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white';
    $textLink = 'font-medium text-gray-950 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-950 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white';
@endphp

<div
    id="demo"
    class="border-rail border-t"
    data-ai-playground
    x-data="{
        scenario: 'batch',
        order: @js(array_keys($scenarios)),
        reducedMotion: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
        thinking: false,
        timer: null,
        deals: @js(array_map(fn (array $deal): array => $deal + ['decision' => null, 'flash' => false, 'out' => false], $deals)),
        batchReplies: @js($batchReplies),
        personReplies: @js($personReplies),
        person: { decision: null, title: true, company: true, flash: false },
        get undecided() {
            return this.deals.filter((deal) => deal.decision === null)
        },
        get keptCount() {
            return this.deals.filter((deal) => ! deal.out).length
        },
        kept(one, other) {
            return (this.keptCount === 1 ? one : other).replace(':count', this.keptCount)
        },
        get batchSummary() {
            return this.batchReplies[this.deals.filter((deal) => deal.decision === 'saved').length]
        },
        get personReply() {
            if (this.person.decision === null) {
                return this.personReplies.prompt
            }

            if (this.person.decision === 'discarded') {
                return this.personReplies.discarded
            }

            return this.person.company ? this.personReplies.linked : this.personReplies.created
        },
        select(key) {
            if (key === this.scenario) {
                return
            }

            this.scenario = key
            this.reset()
        },
        move(step) {
            this.moveTo((this.order.indexOf(this.scenario) + step + this.order.length) % this.order.length)
        },
        moveTo(index) {
            this.select(this.order[index])
            this.$nextTick(() => this.$refs['tab-' + this.order[index]].focus())
        },
        reset() {
            clearTimeout(this.timer)
            this.deals.forEach((deal) => Object.assign(deal, { decision: null, flash: false, out: false }))
            this.person = { decision: null, title: true, company: true, flash: false }
            this.thinking = ! this.reducedMotion
            this.timer = setTimeout(() => this.thinking = false, 700)
        },
        land(record) {
            if (this.reducedMotion) {
                return
            }

            record.flash = true
            setTimeout(() => record.flash = false, 900)
        },
        approveBatch() {
            this.deals.forEach((deal) => {
                deal.decision = deal.out ? 'skipped' : 'saved'

                if (! deal.out) {
                    this.land(deal)
                }
            })
            this.$nextTick(() => this.$refs.batchResult.focus())
        },
        discardBatch() {
            this.deals.forEach((deal) => deal.decision = 'discarded')
            this.$nextTick(() => this.$refs.batchResult.focus())
        },
        decidePerson(decision) {
            this.person.decision = decision

            if (decision === 'created') {
                this.land(this.person)
            }

            this.$nextTick(() => this.$refs['person' + decision].focus())
        },
    }"
>
    <div class="bg-hero-panel flex justify-center px-4 py-8 sm:px-8 md:py-12">
        <x-cloud::marketing.mac-window class="w-full max-w-5xl">
            <div class="grid lg:grid-cols-[minmax(0,1fr)_26rem]">

                <div class="grid min-w-0 border-b border-gray-100 lg:border-r lg:border-b-0 dark:border-white/[0.06]" aria-hidden="true">
                    @foreach($pages as $key => [$pageTitle, $newLabel, $pageIcon])
                        <div class="{{ $page }} {{ $key === 'batch' ? $fadeOpacity : 'invisible opacity-0' }}" x-bind:class="{ 'invisible opacity-0': scenario !== '{{ $key }}', '{{ $fadeOpacity }}': scenario === '{{ $key }}' }" data-ai-page="{{ $key }}">
                            <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-white/[0.06]">
                                <span class="flex min-w-0 items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4 shrink-0 text-gray-400"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons[$pageIcon] }}"/></svg>
                                    <span class="truncate">{{ $pageTitle }}</span>
                                </span>
                                <span class="inline-flex shrink-0 items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-medium text-gray-700 ring-1 ring-gray-200 dark:text-gray-200 dark:ring-white/10">
                                    <x-heroicon-o-chat-bubble-left-right class="h-3.5 w-3.5"/>
                                    {{ __('Ask :name', ['name' => config('chat.assistant_name')]) }}
                                </span>
                            </div>

                            <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                                <span class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-medium text-gray-700 ring-1 ring-gray-200 dark:text-gray-200 dark:ring-white/10">
                                    <x-heroicon-m-bars-3 class="h-3.5 w-3.5 text-gray-400"/>
                                    {{ __('List') }}
                                </span>
                                <span class="inline-flex items-center gap-1 rounded-lg bg-primary-600 px-2.5 py-1 text-xs font-medium text-white">
                                    <x-heroicon-m-plus class="h-3.5 w-3.5"/>
                                    {{ $newLabel }}
                                </span>
                            </div>

                            @if($key === 'batch')
                                <div class="border-t border-gray-100 dark:border-white/[0.06]">
                                    <div class="{{ $head }} grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)] sm:grid-cols-[minmax(0,1.6fr)_minmax(0,1.1fr)_minmax(0,1.3fr)_minmax(0,0.7fr)]">
                                        <span>{{ __('Name') }}</span>
                                        <span class="hidden sm:block">{{ __('Company') }}</span>
                                        <span>{{ __('Stage') }}</span>
                                        <span class="hidden text-right sm:block">{{ __('Amount') }}</span>
                                    </div>
                                    @foreach($opportunityRows as $index => $opportunity)
                                        @php($deal = isset($opportunity['deal']) ? $deals[$opportunity['deal']] : null)
                                        <div class="{{ $row }} {{ $index >= 5 ? 'max-sm:hidden' : '' }} grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)] sm:grid-cols-[minmax(0,1.6fr)_minmax(0,1.1fr)_minmax(0,1.3fr)_minmax(0,0.7fr)]" @if($deal) data-ai-deal-row="{{ $opportunity['deal'] }}" @endif>
                                            @if($deal)
                                                <span class="{{ $flash }} opacity-0" x-bind:class="{ 'opacity-0': ! deals[{{ $opportunity['deal'] }}].flash }"></span>
                                            @endif
                                            <span class="relative truncate font-medium text-gray-900 dark:text-white">{{ $deal['name'] ?? $opportunity['name'] }}</span>
                                            <span class="relative hidden truncate text-gray-600 sm:block dark:text-gray-300">{{ $deal ? 'Kovra Systems' : $opportunity['company'] }}</span>
                                            @if($deal)
                                                <span class="relative truncate text-gray-600 dark:text-gray-300" x-bind:class="{ 'font-medium text-gray-900 dark:text-white': deals[{{ $opportunity['deal'] }}].decision === 'saved' }" x-text="deals[{{ $opportunity['deal'] }}].decision === 'saved' ? @js(__('Negotiation/Review')) : deals[{{ $opportunity['deal'] }}].from" data-ai-deal-stage>{{ $deal['from'] }}</span>
                                            @else
                                                <span class="relative truncate text-gray-600 dark:text-gray-300">{{ $opportunity['stage'] }}</span>
                                            @endif
                                            <span class="relative hidden text-right tabular-nums text-gray-600 sm:block dark:text-gray-300">{{ $deal['amount'] ?? $opportunity['amount'] }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @elseif($key === 'create')
                                <div class="border-t border-gray-100 dark:border-white/[0.06]">
                                    <div class="{{ $head }} grid-cols-2 sm:grid-cols-3">
                                        <span>{{ __('Name') }}</span>
                                        <span>{{ __('Company') }}</span>
                                        <span class="hidden sm:block">{{ __('Job Title') }}</span>
                                    </div>
                                    @foreach($people as $index => [$personName, $company, $jobTitle])
                                        <div class="{{ $row }} {{ $index >= 3 ? 'max-sm:hidden' : '' }} grid-cols-2 sm:grid-cols-3">
                                            <span class="truncate font-medium text-gray-900 dark:text-white">{{ $personName }}</span>
                                            <span class="truncate text-gray-600 dark:text-gray-300">{{ $company }}</span>
                                            <span class="hidden truncate text-gray-600 sm:block dark:text-gray-300">{{ $jobTitle }}</span>
                                        </div>
                                    @endforeach
                                    <div class="{{ $row }} invisible translate-y-1 grid-cols-2 opacity-0 sm:grid-cols-3" x-bind:class="{ 'invisible translate-y-1 opacity-0': person.decision !== 'created', '{{ $fade }}': person.decision === 'created' }" data-ai-person-row>
                                        <span class="{{ $flash }} opacity-0" x-bind:class="{ 'opacity-0': ! person.flash }"></span>
                                        <span class="relative truncate font-medium text-gray-900 dark:text-white">Sarah Chen</span>
                                        <span class="relative truncate text-gray-600 dark:text-gray-300" x-text="person.company ? 'Kovra Systems' : ''">Kovra Systems</span>
                                        <span class="relative hidden truncate text-gray-600 sm:block dark:text-gray-300" x-text="person.title ? @js(__('VP of Engineering')) : ''">{{ __('VP of Engineering') }}</span>
                                    </div>
                                </div>
                            @else
                                <div class="border-t border-gray-100 dark:border-white/[0.06]">
                                    <div class="{{ $head }} grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)] sm:grid-cols-[minmax(0,1.8fr)_minmax(0,1fr)_minmax(0,1fr)]">
                                        <span>{{ __('Title') }}</span>
                                        <span>{{ __('Due Date') }}</span>
                                        <span class="hidden sm:block">{{ __('Company') }}</span>
                                    </div>
                                    @foreach($tasks as $index => [$taskTitle, $due, $company])
                                        <div class="{{ $row }} {{ $index >= 4 ? 'max-sm:hidden' : '' }} grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)] sm:grid-cols-[minmax(0,1.8fr)_minmax(0,1fr)_minmax(0,1fr)]">
                                            <span class="truncate font-medium text-gray-900 dark:text-white">{{ $taskTitle }}</span>
                                            <span @class(['truncate tabular-nums', 'text-red-600 dark:text-red-400' => $due->isPast(), 'text-gray-600 dark:text-gray-300' => ! $due->isPast()])>{{ $due->isoFormat('MMM D, YYYY') }}</span>
                                            <span class="hidden truncate text-gray-600 sm:block dark:text-gray-300">{{ $company }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="flex min-w-0 flex-col">
                    <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-white/[0.06]">
                        <span class="flex min-w-0 items-center gap-2">
                            <x-heroicon-o-chat-bubble-left-right class="h-5 w-5 shrink-0 text-primary-600 dark:text-primary-400" aria-hidden="true"/>
                            <span class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ config('chat.assistant_name') }}</span>
                        </span>
                        <span class="flex shrink-0 items-center gap-2.5 text-gray-400" aria-hidden="true">
                            <x-ri-history-line class="h-4 w-4"/>
                            <x-heroicon-o-x-mark class="h-4 w-4"/>
                        </span>
                    </div>

                    <div id="ai-ask-panel" role="tabpanel" x-bind:aria-labelledby="'ai-ask-' + scenario" class="grid flex-1 content-start p-4">

                        {{-- Batch update: one record at a time, as a standalone batch card shows it. --}}
                        <div class="{{ $panel }} {{ $fadeOpacity }}" x-bind:class="{ 'invisible opacity-0': scenario !== 'batch', '{{ $fadeOpacity }}': scenario === 'batch' }" data-ai-scenario="batch">
                            <p class="{{ $bubble }}">{{ $scenarios['batch'][1] }}</p>

                            <div class="grid">
                                <p class="{{ $tool }} col-start-1 row-start-1 opacity-0" x-bind:class="{ 'opacity-0': ! thinking, '{{ $fadeOpacity }}': thinking }" aria-hidden="true">
                                    <span class="size-1.5 rounded-full bg-gray-400 motion-safe:animate-pulse dark:bg-gray-500"></span>
                                    {{ __('Preparing opportunity changes…') }}
                                </p>
                                <p class="{{ $reply }} {{ $fade }} col-start-1 row-start-1" x-bind:class="{ 'opacity-0': thinking, '{{ $fade }}': ! thinking }" aria-live="polite" x-text="undecided.length ? @js($batchPrompt) : batchSummary">{{ $batchPrompt }}</p>
                                @foreach([$batchPrompt, ...$batchReplies] as $variant)
                                    <p class="{{ $reply }} {{ $ghost }}" aria-hidden="true">{{ $variant }}</p>
                                @endforeach
                            </div>

                            <div class="grid">
                                <div class="col-start-1 row-start-1 space-y-2 {{ $fade }}" x-bind:class="{ 'translate-y-2 opacity-0': thinking, 'invisible opacity-0': ! thinking && ! undecided.length, '{{ $fade }}': ! thinking && undecided.length > 0 }" data-ai-batch-card>
                                    <div class="{{ $card }}">
                                        <div class="flex items-start gap-2.5 px-4 pb-1.5 pt-3">
                                            <span class="{{ $tile }} mt-px" aria-hidden="true">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons['opportunity'] }}"/></svg>
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate text-sm leading-6">
                                                    <span class="text-gray-500 dark:text-gray-400">{{ __('Move') }}</span>
                                                    <span class="font-semibold text-gray-900 dark:text-white" x-text="kept(@js(__(':count opportunity')), @js(__(':count opportunities')))">3 {{ __('opportunities') }}</span>
                                                </span>
                                                <span class="block text-xs leading-5 text-gray-500 dark:text-gray-400">{{ __('Stage to :value', ['value' => __('Negotiation/Review')]) }}</span>
                                            </span>
                                        </div>

                                        <ul class="pb-2 pe-3 ps-[3.125rem]">
                                            @foreach($deals as $index => $deal)
                                                <li class="group/row flex items-center gap-2 border-t border-gray-100 text-sm first:border-t-0 dark:border-white/5" data-ai-batch-record>
                                                    <span class="min-w-0 flex-1 truncate leading-8 text-gray-900 dark:text-white" x-bind:class="{ 'text-gray-400 line-through dark:text-gray-500': deals[{{ $index }}].out }">{{ $deal['name'] }}</span>
                                                    <span class="shrink-0 text-xs text-gray-500 line-through decoration-gray-300 dark:text-gray-400 dark:decoration-gray-600">{{ $deal['from'] }}</span>
                                                    <button
                                                        type="button"
                                                        class="inline-flex min-h-8 min-w-8 shrink-0 cursor-pointer items-center justify-center rounded-md transition focus-visible:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500"
                                                        x-bind:class="deals[{{ $index }}].out ? 'px-1.5 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400' : 'text-gray-400 hover:bg-gray-100 hover:text-gray-700 sm:opacity-0 sm:group-hover/row:opacity-100 dark:hover:bg-white/10 dark:hover:text-gray-200'"
                                                        x-on:click="deals[{{ $index }}].out = ! deals[{{ $index }}].out"
                                                        x-bind:disabled="thinking"
                                                        x-bind:aria-label="deals[{{ $index }}].out ? @js(__('Keep :name', ['name' => $deal['name']])) : @js(__('Leave out :name', ['name' => $deal['name']]))"
                                                        aria-label="{{ __('Leave out :name', ['name' => $deal['name']]) }}"
                                                    >
                                                        <x-heroicon-o-x-mark class="h-3.5 w-3.5" x-show="! deals[{{ $index }}].out" aria-hidden="true"/>
                                                        <span x-show="deals[{{ $index }}].out" style="display: none">{{ __('Keep') }}</span>
                                                    </button>
                                                </li>
                                            @endforeach
                                        </ul>

                                        <div class="flex items-center justify-end gap-2 border-t border-gray-100 px-4 py-2 dark:border-white/5">
                                            <button type="button" class="{{ $discardButton }}" x-on:click="discardBatch()" x-bind:disabled="thinking">{{ __('Discard all') }}</button>
                                            <x-cloud::marketing.rela-approve
                                                x-on:click="approveBatch()"
                                                x-bind:disabled="thinking || ! keptCount"
                                                data-ai-batch-save
                                            ><span x-text="kept(@js(__('Approve :count change')), @js(__('Approve :count changes')))">{{ __('Approve :count changes', ['count' => 3]) }}</span></x-cloud::marketing.rela-approve>
                                        </div>
                                    </div>

                                    <x-cloud::marketing.rela-locked/>
                                </div>

                                <div tabindex="-1" x-ref="batchResult" class="col-start-1 row-start-1 self-start invisible opacity-0 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500" x-bind:class="{ 'invisible opacity-0': undecided.length > 0, '{{ $fade }}': undecided.length === 0 }" data-ai-batch-decided>
                                    <template x-if="deals.some((deal) => deal.decision === 'saved')">
                                        <div>
                                            <p class="px-2 text-xs text-gray-500 dark:text-gray-400" x-text="kept(@js(__('Applied :count change · just now')), @js(__('Applied :count changes · just now')))"></p>
                                            <ul class="mt-1">
                                                <li class="flex min-w-0 items-center gap-1.5 rounded-lg px-2 py-1.5 text-sm text-gray-700 dark:text-gray-300">
                                                    <x-heroicon-o-check class="size-4 shrink-0 text-gray-500 dark:text-gray-400" aria-hidden="true"/>
                                                    <span class="min-w-0 truncate" x-text="kept(@js(__('Moved :count opportunity to :stage')), @js(__('Moved :count opportunities to :stage'))).replace(':stage', @js(__('Negotiation/Review')))"></span>
                                                </li>
                                                @foreach($deals as $index => $deal)
                                                    <li class="flex min-w-0 items-center gap-1.5 rounded-lg px-2 py-1.5 text-sm" x-show="deals[{{ $index }}].decision === 'skipped'">
                                                        <x-heroicon-o-no-symbol class="size-4 shrink-0 text-gray-400 dark:text-gray-500" aria-hidden="true"/>
                                                        <span class="min-w-0 truncate text-gray-500 line-through decoration-gray-300 dark:text-gray-400 dark:decoration-gray-600">{{ $deal['name'] }}</span>
                                                        <span class="shrink-0 text-gray-500 dark:text-gray-400">&middot; {{ __('left out') }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    </template>
                                    <template x-if="deals.every((deal) => deal.decision === 'discarded')">
                                        <x-cloud::marketing.rela-settled class="py-1.5" :summary="__('Move 3 opportunities to :stage', ['stage' => __('Negotiation/Review')])"/>
                                    </template>
                                </div>
                            </div>
                        </div>

                        {{-- Create: the fields it will write, with the identity field locked. --}}
                        <div class="{{ $panel }} invisible opacity-0" x-bind:class="{ 'invisible opacity-0': scenario !== 'create', '{{ $fadeOpacity }}': scenario === 'create' }" data-ai-scenario="create">
                            <p class="{{ $bubble }}">{{ $scenarios['create'][1] }}</p>

                            <div class="grid">
                                <p class="{{ $tool }} col-start-1 row-start-1 opacity-0" x-bind:class="{ 'opacity-0': ! thinking, '{{ $fadeOpacity }}': thinking }" aria-hidden="true">
                                    <span class="size-1.5 rounded-full bg-gray-400 motion-safe:animate-pulse dark:bg-gray-500"></span>
                                    {{ __('Drafting a person…') }}
                                </p>
                                <p class="{{ $reply }} col-start-1 row-start-1" x-bind:class="{ 'opacity-0': thinking, '{{ $fade }}': ! thinking }" aria-live="polite" x-text="personReply">{{ $personReplies['prompt'] }}</p>
                                @foreach($personReplies as $variant)
                                    <p class="{{ $reply }} {{ $ghost }}" aria-hidden="true">{{ $variant }}</p>
                                @endforeach
                            </div>

                            <div class="grid" x-bind:class="{ 'translate-y-2 opacity-0': thinking, '{{ $fade }}': ! thinking }">
                                <div class="col-start-1 row-start-1 space-y-2" x-bind:class="{ 'invisible': person.decision !== null }">
                                    <div class="{{ $card }}">
                                        <div class="flex items-start gap-2.5 px-4 pb-1.5 pt-3">
                                            <span class="{{ $tile }} mt-px" aria-hidden="true">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons['people'] }}"/></svg>
                                            </span>
                                            <p class="min-w-0 truncate text-sm leading-6">
                                                <span class="text-gray-500 dark:text-gray-400">{{ __('Create person') }}</span>
                                                <span class="font-semibold text-gray-900 dark:text-white">Sarah Chen</span>
                                            </p>
                                        </div>

                                        <div class="pb-2 pe-3 ps-[3.125rem]">
                                            <div class="flex items-center gap-3 text-sm">
                                                <span class="w-24 shrink-0 text-xs leading-8 text-gray-500 dark:text-gray-400">{{ __('Name') }}</span>
                                                <span class="min-w-0 flex-1 leading-8 text-gray-900 dark:text-white">Sarah Chen</span>
                                                <x-cloud::home.hero-dock-field :locked="true"/>
                                            </div>
                                            @foreach([['title', __('Job Title'), __('VP of Engineering')], ['company', __('Company'), 'Kovra Systems']] as [$key, $field, $value])
                                                <div class="group/field flex items-center gap-3 text-sm">
                                                    <span class="w-24 shrink-0 text-xs leading-8 text-gray-500 dark:text-gray-400" x-bind:class="{ 'line-through opacity-60': ! person.{{ $key }} }">{{ $field }}</span>
                                                    <span class="min-w-0 flex-1 leading-8 text-gray-900 dark:text-white" x-bind:class="{ 'line-through opacity-60': ! person.{{ $key }} }">{{ $value }}</span>
                                                    <button
                                                        type="button"
                                                        class="inline-flex min-h-8 min-w-8 shrink-0 cursor-pointer items-center justify-center rounded-md transition focus-visible:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500"
                                                        x-bind:class="person.{{ $key }} ? 'text-gray-400 hover:bg-gray-100 hover:text-gray-700 sm:opacity-0 sm:group-hover/field:opacity-100 dark:hover:bg-white/10 dark:hover:text-gray-200' : 'px-1.5 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400'"
                                                        x-bind:disabled="person.decision !== null"
                                                        x-on:click="person.{{ $key }} = ! person.{{ $key }}"
                                                        x-bind:aria-label="person.{{ $key }} ? @js(__('Leave out :field', ['field' => $field])) : @js(__('Keep :field', ['field' => $field]))"
                                                        aria-label="{{ __('Leave out :field', ['field' => $field]) }}"
                                                    >
                                                        <x-heroicon-o-x-mark class="h-3.5 w-3.5" x-show="person.{{ $key }}" aria-hidden="true"/>
                                                        <span x-show="! person.{{ $key }}" style="display: none">{{ __('Keep') }}</span>
                                                    </button>
                                                </div>
                                            @endforeach
                                        </div>

                                        <div class="flex items-center justify-end gap-2 border-t border-gray-100 px-4 py-2 dark:border-white/5">
                                            <button type="button" class="{{ $discardButton }}" x-on:click="decidePerson('discarded')" x-bind:disabled="thinking">{{ __('Discard') }}</button>
                                            <x-cloud::marketing.rela-approve :label="__('Approve')" x-on:click="decidePerson('created')" x-bind:disabled="thinking" data-ai-create-confirm/>
                                        </div>
                                    </div>

                                    <x-cloud::marketing.rela-locked/>
                                </div>

                                <div tabindex="-1" x-ref="personcreated" class="invisible col-start-1 row-start-1 self-start focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500" x-bind:class="{ 'invisible': person.decision !== 'created' }" data-ai-create-done>
                                    <p class="px-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Applied 1 change · just now') }}</p>
                                    <div class="mt-1 flex min-w-0 items-center gap-1.5 rounded-lg px-2 py-1.5 text-sm text-gray-700 dark:text-gray-300">
                                        <x-heroicon-o-check class="size-4 shrink-0 text-gray-500 dark:text-gray-400" aria-hidden="true"/>
                                        <span class="shrink-0 whitespace-nowrap">{{ __('Created') }}</span>
                                        <span class="chat-chip min-w-0" data-record-type="people">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3 w-3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons['people'] }}"/></svg>
                                            <span class="chat-chip-label">Sarah Chen</span>
                                        </span>
                                        <span class="min-w-0 truncate text-gray-500 dark:text-gray-400" x-show="! person.title || ! person.company" x-text="'· ' + @js(__('(:fields left out)')).replace(':fields', [person.title ? null : @js(__('Job Title')), person.company ? null : @js(__('Company'))].filter(Boolean).join(', '))"></span>
                                    </div>
                                </div>

                                <div tabindex="-1" x-ref="persondiscarded" class="invisible col-start-1 row-start-1 self-start focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500" x-bind:class="{ 'invisible': person.decision !== 'discarded' }" data-ai-create-discarded>
                                    <x-cloud::marketing.rela-settled class="py-1.5" :summary="__('Create person Sarah Chen')"/>
                                </div>
                            </div>
                        </div>

                        {{-- Read: answered at once, nothing to approve. --}}
                        <div class="{{ $panel }} invisible opacity-0" x-bind:class="{ 'invisible opacity-0': scenario !== 'read', '{{ $fadeOpacity }}': scenario === 'read' }" data-ai-scenario="read">
                            <p class="{{ $bubble }}">{{ $scenarios['read'][1] }}</p>

                            <div class="grid">
                                <p class="{{ $tool }} col-start-1 row-start-1 opacity-0" x-bind:class="{ 'opacity-0': ! thinking, '{{ $fadeOpacity }}': thinking }" aria-hidden="true">
                                    <span class="size-1.5 rounded-full bg-gray-400 motion-safe:animate-pulse dark:bg-gray-500"></span>
                                    {{ __('Searching tasks…') }}
                                </p>
                                <p class="{{ $reply }} col-start-1 row-start-1" x-bind:class="{ 'opacity-0': thinking, '{{ $fade }}': ! thinking }">{{ __('You have 3 overdue tasks. Reading your workspace never needs your approval.') }}</p>
                            </div>

                            <div class="overflow-hidden rounded-xl border border-[var(--surface-block-border)] bg-[var(--surface-block-bg)]" x-bind:class="{ 'translate-y-2 opacity-0': thinking, '{{ $fade }}': ! thinking }">
                                <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-2.5 dark:border-white/5">
                                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons['task'] }}"/></svg>
                                    </span>
                                    <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Tasks') }}</span>
                                </div>
                                <ul class="divide-y divide-gray-100 dark:divide-white/5">
                                    @foreach(array_filter($tasks, fn (array $task): bool => $task[1]->isPast()) as [$taskTitle, $due])
                                        <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5 text-sm">
                                            <span class="chat-chip min-w-0" data-record-type="task">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons['task'] }}"/></svg>
                                                <span class="chat-chip-label">{{ $taskTitle }}</span>
                                            </span>
                                            <span class="ms-auto text-xs tabular-nums text-red-600 dark:text-red-400">{{ $due->isoFormat('MMM D') }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3 p-4 pt-0">
                        <div class="flex flex-wrap gap-2" role="tablist" aria-label="{{ __('Example requests') }}" x-on:keydown.right.prevent="move(1)" x-on:keydown.left.prevent="move(-1)" x-on:keydown.home.prevent="moveTo(0)" x-on:keydown.end.prevent="moveTo(order.length - 1)">
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
                                    class="{{ $chip }}"
                                >
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>

                        <div class="rounded-xl border border-gray-200 px-3.5 pb-2.5 pt-3 dark:border-white/10" aria-hidden="true">
                            <p class="text-sm text-gray-400 dark:text-gray-500">{{ __('Ask anything...') }}</p>
                            <div class="mt-3 flex items-center justify-between">
                                <x-heroicon-o-paper-clip class="h-4 w-4 text-gray-400"/>
                                <span class="flex h-7 w-7 items-center justify-center rounded-full bg-primary-600/40 text-white">
                                    <x-heroicon-m-arrow-up class="h-4 w-4"/>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between gap-3 border-t border-gray-100 px-4 py-2.5 text-xs text-gray-500 dark:border-white/[0.06] dark:text-gray-400">
                <span>{{ __('A demo with sample records. Nothing here touches a real workspace.') }}</span>
                <button type="button" class="shrink-0 cursor-pointer whitespace-nowrap font-medium text-gray-950 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-950 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white" x-on:click="reset()">{{ __('Start over') }}</button>
            </div>
        </x-cloud::marketing.mac-window>
    </div>

    <figure class="border-rail flex flex-col gap-4 border-t px-6 py-6 md:flex-row md:items-center md:justify-between md:px-10" data-ai-open-rules>
        <div class="min-w-0">
            <x-cloud::marketing.eyebrow>{{ __('In the open') }}</x-cloud::marketing.eyebrow>
            <blockquote class="mt-2 font-mono text-sm text-gray-950 dark:text-white">&ldquo;{{ $approvalRule }}&rdquo;</blockquote>
            <figcaption class="mt-1.5 text-sm text-gray-600 dark:text-gray-400">{{ __('A line from the instructions :name follows. Every one of them is public on GitHub.', ['name' => config('chat.assistant_name')]) }}</figcaption>
        </div>
        <a href="https://github.com/relaticle/relaticle/blob/main/packages/Chat/src/Agents/CrmAssistant.php" target="_blank" rel="noopener" class="{{ $textLink }} inline-flex shrink-0 items-center gap-1 text-sm">
            {{ __('Read the instructions') }}
            <x-ri-arrow-right-up-line class="h-3 w-3 text-gray-400 dark:text-gray-500" aria-hidden="true"/>
        </a>
    </figure>
</div>
