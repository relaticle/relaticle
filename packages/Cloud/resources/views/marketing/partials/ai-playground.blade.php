@php
    use App\Enums\CrmEntity;
    use App\Enums\CustomFieldType;
    use App\Enums\CustomFields\OpportunityField;
    use App\Enums\CustomFields\PeopleField;
    use App\Enums\CustomFields\TaskField;
    use Illuminate\Support\Number;
    use Relaticle\Chat\Enums\PendingActionOperation;
    use Relaticle\Chat\Enums\ProposalEntity;

    $iconOf = fn (OpportunityField|PeopleField|TaskField $field): string => CustomFieldType::from($field->getFieldType())->icon();
    $option = function (OpportunityField|TaskField $field, string $entity, string $option): array {
        $key = "enums/custom-fields.{$entity}.{$field->value}.options.{$option}";

        return ['badge' => __($key), 'color' => $field->optionKeyColors()[$key]];
    };
    $stage = fn (string $name): array => $option(OpportunityField::STAGE, 'opportunity', $name);
    $status = fn (string $name): array => $option(TaskField::STATUS, 'task', $name);
    $money = fn (int $amount): string => Number::currency($amount, 'USD', app()->getLocale());
    $listView = fn (string $records): string => __('filament/panel.tables.views.standard', ['records' => $records]);
    $newLabel = fn (string $label): string => __('filament-actions::create.single.label', ['label' => $label]);

    $scenarios = [
        'batch' => [__('Update three opportunities'), __('Move the three Kovra deals to Negotiation')],
        'create' => [__('Add a person'), __('Add Sarah Chen at Kovra Systems. She is VP of Engineering.')],
        'read' => [__('Find overdue tasks'), __('What is overdue?')],
    ];

    $target = $stage('negotiation_review');

    $deals = [
        ['name' => __('Kovra renewal'), 'from' => $stage('qualification'), 'amount' => 24000, 'closes' => now()->addDays(38)],
        ['name' => __('Kovra add-on seats'), 'from' => $stage('proposal_price_quote'), 'amount' => 8500, 'closes' => now()->addDays(19)],
        ['name' => __('Kovra platform expansion'), 'from' => $stage('qualification'), 'amount' => 62000, 'closes' => now()->addDays(71)],
    ];

    $opportunityRows = [
        ['deal' => 0],
        [__('Trellis Labs pilot'), $money(12000), now()->addDays(12)->isoFormat('ll'), $stage('negotiation_review')],
        ['deal' => 1],
        [__('Lumen Health onboarding'), $money(18000), now()->addDays(26)->isoFormat('ll'), $stage('proposal_price_quote')],
        ['deal' => 2],
        [__('Northwind renewal'), $money(48000), now()->subDays(9)->isoFormat('ll'), $stage('closed_won')],
        [__('Halcyon Freight rollout'), $money(31000), now()->addDays(54)->isoFormat('ll'), $stage('qualification')],
        [__('Brightline analytics seats'), $money(9600), now()->addDays(33)->isoFormat('ll'), $stage('proposal_price_quote')],
    ];

    $opportunityRows = array_map(function (array $row) use ($deals, $money, $target): array {
        if (! isset($row['deal'])) {
            return $row;
        }

        $index = $row['deal'];
        $deal = $deals[$index];

        return [
            'cells' => [
                $deal['name'],
                $money($deal['amount']),
                $deal['closes']->isoFormat('ll'),
                [
                    $deal['from'] + ['attributes' => ['x-show' => "deals[{$index}].decision !== 'saved'"]],
                    $target + ['attributes' => ['x-show' => "deals[{$index}].decision === 'saved'", 'style' => 'display: none', 'data-ai-deal-moved' => true]],
                ],
            ],
            'attributes' => [
                'data-ai-deal-row' => $index,
                'class' => 'transition-colors duration-[450ms] ease-out motion-reduce:transition-none',
                'x-bind:class' => "{ 'bg-primary-500/10 dark:bg-primary-400/15': deals[{$index}].flash }",
            ],
        ];
    }, $opportunityRows);

    $kovra = ['record' => 'Kovra Systems', 'icon' => CrmEntity::Company->icon()];

    $peopleRows = [
        ['Lena Ortiz', ['record' => 'Northwind', 'icon' => CrmEntity::Company->icon()], __('Head of Operations')],
        ['Marcus Webb', ['record' => 'Trellis Labs', 'icon' => CrmEntity::Company->icon()], __('CTO')],
        ['Priya Nair', ['record' => 'Lumen Health', 'icon' => CrmEntity::Company->icon()], __('Procurement Lead')],
        ['Tomás Reyes', ['record' => 'Halcyon Freight', 'icon' => CrmEntity::Company->icon()], __('Head of Logistics')],
        ['Emma Lindqvist', ['record' => 'Brightline', 'icon' => CrmEntity::Company->icon()], __('Revenue Operations')],
        ['Daniel Okafor', $kovra, __('Engineering Manager')],
        [
            'cells' => ['Sarah Chen', $kovra, ['text' => __('VP of Engineering'), 'attributes' => ['x-show' => 'person.title']]],
            'attributes' => [
                'data-ai-person-row' => true,
                'class' => 'invisible transition-colors duration-[450ms] ease-out motion-reduce:transition-none',
                'x-bind:class' => "{ 'invisible': person.decision !== 'created', 'bg-primary-500/10 dark:bg-primary-400/15': person.flash }",
            ],
        ],
    ];

    $tasks = [
        [__('Call Sarah Chen'), now()->subDay(), 'Kovra Systems', 'to_do'],
        [__('Send proposal to Trellis Labs'), now()->subDays(2), 'Trellis Labs', 'in_progress'],
        [__('Schedule demo with Kovra Systems'), now()->subDays(4), 'Kovra Systems', 'to_do'],
        [__('Send renewal terms to Northwind'), now()->addDays(3), 'Northwind', 'to_do'],
        [__('Prepare Halcyon Freight kickoff'), now()->addDays(5), 'Halcyon Freight', 'in_progress'],
        [__('Review Brightline security questionnaire'), now()->addDays(6), 'Brightline', 'to_do'],
        [__('Follow up with Lena Ortiz'), now()->addDays(8), 'Northwind', 'to_do'],
    ];

    $overdue = collect($tasks)->filter(fn (array $task): bool => $task[1]->isPast())->sortBy(fn (array $task) => $task[1])->values();

    $pages = [
        'batch' => [
            'entity' => CrmEntity::Opportunity,
            'resource' => 'opportunity',
            'columns' => [
                ['label' => __('Name')],
                ['label' => OpportunityField::AMOUNT->getDisplayName(), 'icon' => $iconOf(OpportunityField::AMOUNT)],
                ['label' => OpportunityField::CLOSE_DATE->getDisplayName(), 'icon' => $iconOf(OpportunityField::CLOSE_DATE)],
                ['label' => OpportunityField::STAGE->getDisplayName(), 'icon' => $iconOf(OpportunityField::STAGE)],
            ],
            'rows' => $opportunityRows,
            'shape' => 'square',
        ],
        'create' => [
            'entity' => CrmEntity::People,
            'resource' => 'person',
            'columns' => [
                ['label' => __('filament/resources/person.fields.name.label')],
                ['label' => __('filament/resources/person.fields.company.label'), 'icon' => CrmEntity::Company->icon()],
                ['label' => PeopleField::JOB_TITLE->getDisplayName(), 'icon' => $iconOf(PeopleField::JOB_TITLE)],
            ],
            'rows' => $peopleRows,
            'shape' => 'round',
        ],
        'read' => [
            'entity' => CrmEntity::Task,
            'resource' => 'task',
            'columns' => [
                ['label' => __('filament/resources/task.fields.title.label')],
                ['label' => __('filament/components/linked-records.label'), 'icon' => CustomFieldType::RECORD->icon()],
                ['label' => TaskField::STATUS->getDisplayName(), 'icon' => $iconOf(TaskField::STATUS)],
            ],
            'rows' => array_map(fn (array $task): array => [
                $task[0],
                ['record' => $task[2], 'icon' => CrmEntity::Company->icon(), 'tone' => 'underline'],
                $status($task[3]),
            ], $tasks),
            'shape' => null,
        ],
    ];

    $batchTitle = ProposalEntity::Opportunity->batchSummary(PendingActionOperation::Update, count($deals));
    $stageLabel = OpportunityField::STAGE->getDisplayName();
    $jobTitleLabel = PeopleField::JOB_TITLE->getDisplayName();
    $createdVerb = ProposalEntity::People->receiptVerb(PendingActionOperation::Create);
    $justNow = __('just now');

    $receiptTexts = [
        'head' => [
            'one' => __('Applied :count change · :when', ['when' => $justNow]),
            'other' => __('Applied :count changes · :when', ['when' => $justNow]),
        ],
        'moved' => [
            'one' => __('Moved :count :entities to :value', ['entities' => ProposalEntity::Opportunity->plural(1), 'value' => $target['badge']]),
            'other' => __('Moved :count :entities to :value', ['entities' => ProposalEntity::Opportunity->plural(2), 'value' => $target['badge']]),
        ],
        'approve' => [
            'one' => trans_choice('Approve :count change|Approve :count changes', 1, ['count' => ':count']),
            'other' => trans_choice('Approve :count change|Approve :count changes', 2, ['count' => ':count']),
        ],
    ];

    $batchFollowUps = [
        __('Okay, I left all :count opportunities as they were.', ['count' => count($deals)]),
        ...array_map(
            fn (int $saved): string => $saved === count($deals)
                ? __('Done. Moved all :count opportunities to Negotiation/Review.', ['count' => $saved])
                : __('Done. Moved :saved of :count opportunities to Negotiation/Review.', ['saved' => $saved, 'count' => count($deals)]),
            range(1, count($deals)),
        ),
    ];

    $fade = 'transition duration-300 ease-out motion-reduce:transition-none';
    $fadeOpacity = 'transition-opacity duration-300 ease-out motion-reduce:transition-none';
    $stack = 'col-start-1 row-start-1';
    $bubble = 'ml-auto w-fit max-w-[85%] break-words rounded-2xl rounded-br-md bg-gray-100 px-4 py-2.5 text-sm text-gray-900 dark:bg-white/10 dark:text-gray-100';
    $reply = 'px-1 text-sm leading-6 text-gray-900 dark:text-gray-100';
    $thinkingLine = 'flex items-center gap-2 px-1 py-2 text-sm text-gray-600 dark:text-gray-300';
    $dock = 'mb-3 overflow-hidden rounded-xl border border-primary-200 bg-[var(--surface-block-bg)] ring-[3px] ring-primary-100 dark:border-primary-400/30 dark:ring-primary-400/10';
    $tile = 'mt-px flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400';
    $toggle = 'inline-flex min-h-8 min-w-8 shrink-0 cursor-pointer items-center justify-center rounded-md transition focus-visible:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:cursor-not-allowed disabled:opacity-60';
    $toggleIdle = 'text-gray-400 hover:bg-gray-100 hover:text-gray-700 sm:opacity-0 dark:hover:bg-white/10 dark:hover:text-gray-200';
    $toggleKeep = 'px-1.5 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400';
    $discardButton = 'inline-flex h-7 cursor-pointer items-center rounded-md px-2.5 text-xs font-medium text-gray-600 transition hover:bg-gray-100 hover:text-gray-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:cursor-not-allowed disabled:opacity-60 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white';
    $receiptFocus = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500';
    $tabActive = 'border-gray-200 bg-gray-100 text-gray-950 dark:border-white/[0.1] dark:bg-gray-900 dark:text-white';
    $tabIdle = 'border-transparent text-gray-500 hover:text-gray-950 dark:text-gray-400 dark:hover:text-white';
    $textLink = 'font-medium text-gray-950 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-950 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white';
    $approvalRule = "Every write needs the user's approval.";
@endphp

<div
    id="demo"
    data-ai-playground
    x-data="{
        scenario: 'batch',
        order: @js(array_keys($scenarios)),
        reducedMotion: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
        thinking: false,
        timer: null,
        deals: @js(array_map(fn (array $deal): array => ['amount' => $deal['amount'], 'decision' => null, 'flash' => false, 'out' => false], $deals)),
        texts: @js($receiptTexts),
        batchFollowUps: @js($batchFollowUps),
        person: { decision: null, title: true, flash: false },
        get undecided() {
            return this.deals.filter((deal) => deal.decision === null)
        },
        get keptCount() {
            return this.deals.filter((deal) => ! deal.out).length
        },
        get savedCount() {
            return this.deals.filter((deal) => deal.decision === 'saved').length
        },
        get pending() {
            return ! this.thinking && ((this.scenario === 'batch' && this.undecided.length > 0) || (this.scenario === 'create' && this.person.decision === null))
        },
        counted(text, count) {
            return (count === 1 ? text.one : text.other).replace(':count', count)
        },
        get savedTotal() {
            const total = this.deals.filter((deal) => deal.decision === 'saved').reduce((sum, deal) => sum + deal.amount, 0)

            return new Intl.NumberFormat(document.documentElement.lang || 'en', { style: 'currency', currency: 'USD' }).format(total)
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
            this.person = { decision: null, title: true, flash: false }
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
    <div class="flex flex-col items-center px-4 py-8 sm:px-8 md:py-12">
        <div class="flex w-full max-w-5xl overflow-x-auto [scrollbar-width:none] sm:justify-center" role="tablist" aria-label="{{ __('Example requests') }}" x-on:keydown.right.prevent="move(1)" x-on:keydown.left.prevent="move(-1)" x-on:keydown.home.prevent="moveTo(0)" x-on:keydown.end.prevent="moveTo(order.length - 1)">
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
                    x-bind:class="{ @js($tabActive): scenario === '{{ $key }}', @js($tabIdle): scenario !== '{{ $key }}' }"
                    x-on:click="select('{{ $key }}')"
                    @class(['relative -mb-px shrink-0 cursor-pointer whitespace-nowrap rounded-t-lg border border-b-0 px-3 py-2 text-xs font-medium transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-500 sm:px-5 sm:text-sm', $key === 'batch' ? $tabActive : $tabIdle])
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <x-cloud::marketing.mac-window class="w-full max-w-5xl">
            <div class="grid grid-cols-1 lg:h-[640px] lg:grid-cols-[minmax(0,1fr)_420px]">
                <div class="grid min-w-0 overflow-hidden border-gray-200 max-lg:hidden lg:border-r dark:border-gray-700" aria-hidden="true">
                    @foreach($pages as $key => $page)
                        <div class="{{ $stack }} min-w-0 {{ $key === 'batch' ? $fadeOpacity : 'invisible opacity-0' }}" x-bind:class="{ 'invisible opacity-0': scenario !== '{{ $key }}', '{{ $fadeOpacity }}': scenario === '{{ $key }}' }" data-ai-page="{{ $key }}">
                            <x-cloud::marketing.app-screen
                                :framed="false"
                                :icon="$page['entity']->icon()"
                                :title="__('filament/resources/'.$page['resource'].'.navigation_label')"
                                :view="$listView(__('filament/resources/'.$page['resource'].'.plural_label'))"
                                :new-label="$newLabel(__('filament/resources/'.$page['resource'].'.label'))"
                            >
                                <x-cloud::marketing.app-list
                                    :columns="$page['columns']"
                                    :rows="$page['rows']"
                                    :record-icon="$page['shape'] === null ? false : $page['entity']->icon()"
                                    :record-shape="$page['shape'] ?? 'square'"
                                />
                            </x-cloud::marketing.app-screen>
                        </div>
                    @endforeach
                </div>

                <x-cloud::marketing.rela-panel class="min-h-[600px] lg:min-h-0">
                    <div id="ai-ask-panel" role="tabpanel" x-bind:aria-labelledby="'ai-ask-' + scenario" class="grid flex-1 grid-cols-1 content-start">

                        <div class="{{ $stack }} space-y-6 {{ $fadeOpacity }}" x-bind:class="{ 'invisible opacity-0': scenario !== 'batch', '{{ $fadeOpacity }}': scenario === 'batch' }" data-ai-scenario="batch">
                            <div class="pb-8"><p class="{{ $bubble }}">{{ $scenarios['batch'][1] }}</p></div>

                            <div class="grid grid-cols-1">
                                <p class="{{ $thinkingLine }} {{ $stack }} opacity-0" x-bind:class="{ 'opacity-0': ! thinking, '{{ $fadeOpacity }}': thinking }" aria-hidden="true">
                                    <span class="size-1.5 rounded-full bg-gray-400 motion-safe:animate-pulse dark:bg-gray-500"></span>
                                    {{ __('Preparing opportunity changes…') }}
                                </p>
                                <p class="{{ $reply }} {{ $fade }} {{ $stack }}" x-bind:class="{ 'opacity-0': thinking, '{{ $fade }}': ! thinking }">{{ __('Three Kovra opportunities will move to Negotiation/Review.') }}</p>
                            </div>

                            <div tabindex="-1" x-ref="batchResult" class="invisible pt-7 {{ $receiptFocus }}" x-bind:class="{ 'invisible': undecided.length > 0 }" aria-live="polite" data-ai-batch-decided>
                                <template x-if="savedCount > 0">
                                    <div>
                                        <p class="px-2 text-xs text-gray-500 dark:text-gray-400" x-text="counted(texts.head, savedCount)"></p>
                                        <div class="mt-1 space-y-0.5">
                                            <x-cloud::marketing.rela-receipt-line>
                                                <x-slot:label x-text="counted(texts.moved, savedCount)"></x-slot:label>
                                                <x-slot:meta x-text="savedTotal"></x-slot:meta>
                                            </x-cloud::marketing.rela-receipt-line>
                                            @foreach($deals as $index => $deal)
                                                <x-cloud::marketing.rela-receipt-line outcome="left_out" :name="$deal['name']" x-show="deals[{{ $index }}].decision === 'skipped'"/>
                                            @endforeach
                                        </div>
                                    </div>
                                </template>
                                <template x-if="undecided.length === 0 && savedCount === 0">
                                    <x-cloud::marketing.rela-settled class="py-1.5" :summary="$batchTitle"/>
                                </template>
                                <p class="{{ $reply }} mt-5" x-text="batchFollowUps[savedCount]" data-ai-batch-followup></p>
                            </div>
                        </div>

                        <div class="{{ $stack }} invisible space-y-6 opacity-0" x-bind:class="{ 'invisible opacity-0': scenario !== 'create', '{{ $fadeOpacity }}': scenario === 'create' }" data-ai-scenario="create">
                            <div class="pb-8"><p class="{{ $bubble }}">{{ $scenarios['create'][1] }}</p></div>

                            <div class="grid grid-cols-1">
                                <p class="{{ $thinkingLine }} {{ $stack }} opacity-0" x-bind:class="{ 'opacity-0': ! thinking, '{{ $fadeOpacity }}': thinking }" aria-hidden="true">
                                    <span class="size-1.5 rounded-full bg-gray-400 motion-safe:animate-pulse dark:bg-gray-500"></span>
                                    {{ __('Drafting a person…') }}
                                </p>
                                <p class="{{ $reply }} {{ $stack }}" x-bind:class="{ 'opacity-0': thinking, '{{ $fade }}': ! thinking }">{{ __('I will add Sarah Chen at Kovra Systems as VP of Engineering.') }}</p>
                            </div>

                            <div class="grid grid-cols-1 pt-7" aria-live="polite">
                                <div tabindex="-1" x-ref="personcreated" class="{{ $stack }} invisible self-start {{ $receiptFocus }}" x-bind:class="{ 'invisible': person.decision !== 'created' }" data-ai-create-done>
                                    <p class="px-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Applied :count change · :when', ['count' => 1, 'when' => $justNow]) }}</p>
                                    <x-cloud::marketing.rela-receipt-line class="mt-1" :verb="$createdVerb" type="people" name="Sarah Chen">
                                        <x-slot:meta x-show="! person.title">{{ __('(:fields left out)', ['fields' => $jobTitleLabel]) }}</x-slot:meta>
                                    </x-cloud::marketing.rela-receipt-line>
                                    <p class="{{ $reply }} mt-5" data-ai-create-followup>{{ __('Sarah Chen has been created at Kovra Systems.') }}</p>
                                </div>

                                <div tabindex="-1" x-ref="persondiscarded" class="{{ $stack }} invisible self-start {{ $receiptFocus }}" x-bind:class="{ 'invisible': person.decision !== 'discarded' }" data-ai-create-discarded>
                                    <x-cloud::marketing.rela-settled class="py-1.5" :summary="ProposalEntity::People->createSummary('Sarah Chen')"/>
                                    <p class="{{ $reply }} mt-5">{{ __('Okay, I did not add Sarah Chen.') }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="{{ $stack }} invisible space-y-6 opacity-0" x-bind:class="{ 'invisible opacity-0': scenario !== 'read', '{{ $fadeOpacity }}': scenario === 'read' }" data-ai-scenario="read">
                            <div class="pb-8"><p class="{{ $bubble }}">{{ $scenarios['read'][1] }}</p></div>

                            <div class="space-y-3">
                                <div class="grid grid-cols-1">
                                    <p class="{{ $thinkingLine }} {{ $stack }} opacity-0" x-bind:class="{ 'opacity-0': ! thinking, '{{ $fadeOpacity }}': thinking }" aria-hidden="true">
                                        <span class="size-1.5 rounded-full bg-gray-400 motion-safe:animate-pulse dark:bg-gray-500"></span>
                                        {{ __('Searching tasks…') }}
                                    </p>
                                    <p class="{{ $reply }} {{ $stack }}" x-bind:class="{ 'opacity-0': thinking, '{{ $fade }}': ! thinking }">{{ __('You have 3 overdue tasks. Reading your workspace never needs your approval.') }}</p>
                                </div>

                                <x-cloud::marketing.rela-records
                                    x-bind:class="{ 'translate-y-2 opacity-0': thinking, '{{ $fade }}': ! thinking }"
                                    :title="__('filament/resources/task.navigation_label')"
                                    type="task"
                                    :sort="TaskField::DUE_DATE->getDisplayName()"
                                    :columns="[TaskField::DUE_DATE->getDisplayName(), __('filament/resources/task.fields.title.label'), TaskField::STATUS->getDisplayName(), TaskField::PRIORITY->getDisplayName()]"
                                    :rows="$overdue->map(fn (array $task): array => [$task[1]->isoFormat('ll'), ['chip' => $task[0]], $status($task[3])['badge'], ''])->all()"
                                />
                            </div>
                        </div>
                    </div>

                    <x-slot:footer class="grid grid-cols-1">
                        <div class="{{ $stack }} self-end" x-show="scenario === 'batch' && pending" data-ai-batch-card>
                            <div class="{{ $dock }}" role="group" aria-label="{{ __('Proposal awaiting your decision') }}">
                                <div class="flex items-start gap-2.5 px-4 pb-1.5 pt-3">
                                    <span class="{{ $tile }}" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="{{ \Relaticle\Chat\Support\RecordChipRenderer::iconPath('opportunity') }}"/></svg>
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-semibold leading-6 text-gray-900 dark:text-white">{{ $batchTitle }}</span>
                                        <span class="block text-xs leading-5 text-gray-500 dark:text-gray-400">{{ __(':field to :value', ['field' => $stageLabel, 'value' => $target['badge']]) }}</span>
                                    </span>
                                </div>

                                <div class="divide-y divide-gray-100 pb-2 pe-2 ps-[3.125rem] dark:divide-white/5">
                                    @foreach($deals as $index => $deal)
                                        <div class="group/row py-1.5" data-ai-batch-record>
                                            <div class="flex items-center gap-2">
                                                <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900 dark:text-white" x-bind:class="{ 'text-gray-400 line-through dark:text-gray-500': deals[{{ $index }}].out, 'text-gray-900 dark:text-white': ! deals[{{ $index }}].out }">{{ $deal['name'] }}</span>
                                                <button
                                                    type="button"
                                                    class="{{ $toggle }} {{ $toggleIdle }} sm:group-hover/row:opacity-100"
                                                    x-bind:class="{ @js($toggleKeep): deals[{{ $index }}].out, @js($toggleIdle.' sm:group-hover/row:opacity-100'): ! deals[{{ $index }}].out }"
                                                    x-on:click="deals[{{ $index }}].out = ! deals[{{ $index }}].out"
                                                    x-bind:aria-label="deals[{{ $index }}].out ? @js(__('Keep :name', ['name' => $deal['name']])) : @js(__('Leave out :name', ['name' => $deal['name']]))"
                                                    aria-label="{{ __('Leave out :name', ['name' => $deal['name']]) }}"
                                                >
                                                    <x-lucide-x class="h-4 w-4" x-show="! deals[{{ $index }}].out" aria-hidden="true"/>
                                                    <span x-show="deals[{{ $index }}].out" style="display: none">{{ __('Keep') }}</span>
                                                </button>
                                            </div>
                                            <x-cloud::marketing.app-plan-fields x-show="! deals[{{ $index }}].out" :fields="[[$stageLabel, $target['badge'], 'change', $deal['from']['badge']]]"/>
                                        </div>
                                    @endforeach
                                </div>

                                <div class="flex items-center justify-end gap-2 border-t border-gray-100 px-4 py-2 dark:border-white/5">
                                    <button type="button" class="{{ $discardButton }}" x-on:click="discardBatch()">{{ __('Discard all') }}</button>
                                    <x-cloud::marketing.rela-approve x-on:click="approveBatch()" x-bind:disabled="! keptCount" data-ai-batch-save>
                                        <span x-text="counted(texts.approve, keptCount)">{{ trans_choice('Approve :count change|Approve :count changes', count($deals), ['count' => count($deals)]) }}</span>
                                    </x-cloud::marketing.rela-approve>
                                </div>
                            </div>
                        </div>

                        <div class="{{ $stack }} self-end" x-show="scenario === 'create' && pending" style="display: none" data-ai-create-card>
                            <div class="{{ $dock }}" role="group" aria-label="{{ __('Proposal awaiting your decision') }}">
                                <div class="flex items-start gap-2.5 px-4 pb-1.5 pt-3">
                                    <span class="{{ $tile }}" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="{{ \Relaticle\Chat\Support\RecordChipRenderer::iconPath('people') }}"/></svg>
                                    </span>
                                    <span class="block min-w-0 flex-1 truncate text-sm leading-6">
                                        <span class="text-gray-500 dark:text-gray-400">{{ ProposalEntity::People->createLabel() }}</span>
                                        <span class="font-semibold text-gray-900 dark:text-white">Sarah Chen</span>
                                    </span>
                                </div>

                                <div class="pb-2 pe-2 ps-[3.125rem]">
                                    @foreach([['name', __('Name'), 'Sarah Chen', true], ['company', __('filament/resources/person.fields.company.label'), 'Kovra Systems', false], ['title', $jobTitleLabel, __('VP of Engineering'), true]] as [$key, $field, $value, $editable])
                                        <div class="group/field flex items-start gap-3">
                                            <span class="w-24 shrink-0 truncate py-1.5 text-xs leading-5 text-gray-500 sm:w-32 dark:text-gray-400" @if($key === 'title') x-bind:class="{ 'line-through opacity-60': ! person.title }" @endif>{{ $field }}</span>
                                            <span class="flex min-w-0 flex-1 items-start gap-1.5" @if($key === 'title') x-bind:class="{ 'line-through opacity-60': ! person.title }" @endif>
                                                <span class="min-w-0 flex-1 break-words py-1.5 text-sm leading-5 text-gray-900 dark:text-white">{{ $value }}</span>
                                                @if($editable)
                                                    <span class="inline-flex min-h-8 min-w-8 shrink-0 items-center justify-center rounded-md text-gray-400 sm:opacity-0 sm:group-hover/field:opacity-100" aria-hidden="true">
                                                        <x-lucide-square-pen class="h-3.5 w-3.5"/>
                                                    </span>
                                                @endif
                                            </span>
                                            @if($key === 'title')
                                                <button
                                                    type="button"
                                                    class="{{ $toggle }} {{ $toggleIdle }} sm:group-hover/field:opacity-100"
                                                    x-bind:class="{ @js($toggleIdle.' sm:group-hover/field:opacity-100'): person.title, @js($toggleKeep): ! person.title }"
                                                    x-on:click="person.title = ! person.title"
                                                    x-bind:aria-label="person.title ? @js(__('Leave out :field', ['field' => $field])) : @js(__('Keep :field', ['field' => $field]))"
                                                    aria-label="{{ __('Leave out :field', ['field' => $field]) }}"
                                                >
                                                    <x-lucide-x class="h-3.5 w-3.5" x-show="person.title" aria-hidden="true"/>
                                                    <span x-show="! person.title" style="display: none">{{ __('Keep') }}</span>
                                                </button>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>

                                <div class="flex items-center justify-end gap-2 border-t border-gray-100 px-4 py-2 dark:border-white/5">
                                    <button type="button" class="{{ $discardButton }}" x-on:click="decidePerson('discarded')">{{ ProposalEntity::People->declineLabel() }}</button>
                                    <x-cloud::marketing.rela-approve :label="ProposalEntity::People->confirmLabel(PendingActionOperation::Create)" x-on:click="decidePerson('created')" data-ai-create-confirm/>
                                </div>
                            </div>
                        </div>

                        <x-cloud::marketing.rela-composer class="{{ $stack }} self-end" x-show="! pending" style="display: none" data-ai-composer/>
                    </x-slot:footer>
                </x-cloud::marketing.rela-panel>
            </div>
        </x-cloud::marketing.mac-window>

        <div class="mt-3 flex w-full max-w-5xl items-center justify-between gap-3 px-1 text-xs text-gray-500 dark:text-gray-400">
            <span>{{ __('A demo with sample records. Nothing here touches a real workspace.') }}</span>
            <button type="button" class="{{ $textLink }} shrink-0 cursor-pointer whitespace-nowrap" x-on:click="reset()">{{ __('Start over') }}</button>
        </div>
    </div>

    <figure class="border-rail flex flex-col gap-4 border-t px-6 py-6 md:flex-row md:items-center md:justify-between md:px-10" data-ai-open-rules>
        <div class="min-w-0">
            <x-cloud::marketing.eyebrow>{{ __('In the open') }}</x-cloud::marketing.eyebrow>
            <blockquote class="mt-2 font-mono text-sm text-gray-950 dark:text-white">&ldquo;{{ $approvalRule }}&rdquo;</blockquote>
            <figcaption class="mt-1.5 text-sm text-gray-600 dark:text-gray-400">{{ __('A line from the instructions :name follows. Every one of them is public on GitHub.', ['name' => config('chat.assistant_name')]) }}</figcaption>
        </div>
        <a href="https://github.com/relaticle/relaticle/blob/main/packages/Chat/src/Agents/CrmAssistant.php" target="_blank" rel="noopener" class="{{ $textLink }} inline-flex shrink-0 items-center gap-1 text-sm">
            {{ __('Read the instructions') }}
            <x-lucide-arrow-up-right class="h-3 w-3 text-gray-400 dark:text-gray-500" aria-hidden="true"/>
        </a>
    </figure>
</div>
