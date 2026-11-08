@php
    $actorKey = $assistant->value;
    $mentionPrefix = $assistant === \Relaticle\Cloud\Marketing\Enums\ConnectableAssistant::ChatGPT ? '@Relaticle ' : '';

    $asks = [
        'pipeline' => [
            'label' => __('Pipeline'),
            'prompt' => __('What is in my pipeline this month?'),
            'tools' => [__('Aggregate opportunities')],
            'reply' => __('You have 9 open opportunities worth $181,500. The largest share sits in proposals.'),
        ],
        'person' => [
            'label' => __('Add a person'),
            'prompt' => __('Add Dana Reyes at Northwind Labs and link her to the company.'),
            'tools' => [__('Create company'), __('Create person')],
            'reply' => __('Done. Dana Reyes is created and linked to Northwind Labs.'),
            'record' => [__('People'), __('Dana Reyes'), [[__('VP of Sales'), null], ['Northwind Labs', null]]],
        ],
        'task' => [
            'label' => __('Log a task'),
            'prompt' => __('Create a task to send the proposal on Friday, on the Northwind opportunity.'),
            'tools' => [__('Create task')],
            'reply' => __('Done. The task is due Friday and attached to the Northwind renewal.'),
            'record' => [__('Tasks'), __('Send the proposal'), [[__('Friday'), 'lucide-calendar'], [__('Northwind renewal'), null]]],
        ],
        'notes' => [
            'label' => __('Recall a note'),
            'prompt' => __('What did we agree with Northwind last time?'),
            'tools' => [__('List notes')],
            'reply' => __('From the note on 14 October: a pilot through December at $4,000 a month, and single sign-on before the rollout.'),
        ],
    ];

    $stages = [
        [__('Qualification'), 4, '$48,000'],
        [__('Proposal/Price Quote'), 3, '$72,500'],
        [__('Negotiation/Review'), 2, '$61,000'],
    ];
@endphp

<figure
    class="feat-card px-3 py-6 sm:px-8 md:py-12"
    data-assistant-playground
    x-data="{ ask: 'pipeline', asks: @js(array_keys($asks)) }"
    x-on:keydown.left.prevent="ask = asks[(asks.indexOf(ask) + asks.length - 1) % asks.length]; $nextTick(() => $refs[ask].focus())"
    x-on:keydown.right.prevent="ask = asks[(asks.indexOf(ask) + 1) % asks.length]; $nextTick(() => $refs[ask].focus())"
>
    <div class="mx-auto w-full max-w-3xl">
        <div role="tablist" aria-label="{{ __('Example asks') }}" class="mb-4 flex flex-wrap justify-center gap-2">
            @foreach($asks as $key => $ask)
                <button
                    type="button"
                    role="tab"
                    id="assistant-ask-{{ $key }}"
                    aria-controls="assistant-panel-{{ $key }}"
                    aria-selected="{{ $key === 'pipeline' ? 'true' : 'false' }}"
                    tabindex="{{ $key === 'pipeline' ? '0' : '-1' }}"
                    x-ref="{{ $key }}"
                    x-bind:aria-selected="(ask === '{{ $key }}').toString()"
                    x-bind:tabindex="ask === '{{ $key }}' ? 0 : -1"
                    x-on:click="ask = '{{ $key }}'"
                    class="inline-flex h-9 cursor-pointer items-center rounded-full border border-white/60 bg-white/60 px-4 text-sm font-medium text-gray-700 backdrop-blur-sm transition-colors hover:bg-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary aria-selected:border-gray-950 aria-selected:bg-gray-950 aria-selected:text-white dark:border-white/10 dark:bg-white/[0.06] dark:text-gray-300 dark:hover:bg-white/10 dark:aria-selected:border-white dark:aria-selected:bg-white dark:aria-selected:text-gray-950"
                >
                    {{ $ask['label'] }}
                </button>
            @endforeach
        </div>

        <div class="grid">
            @foreach($asks as $key => $ask)
                <div
                    id="assistant-panel-{{ $key }}"
                    role="tabpanel"
                    aria-labelledby="assistant-ask-{{ $key }}"
                    data-assistant-ask="{{ $key }}"
                    class="col-start-1 row-start-1 {{ $key === 'pipeline' ? '' : 'invisible' }}"
                    x-bind:class="{ 'invisible': ask !== '{{ $key }}' }"
                    x-bind:aria-hidden="(ask !== '{{ $key }}').toString()"
                >
                    <x-cloud::marketing.window class="w-full">
                        <div class="border-rail flex items-center gap-2 border-b px-4 py-2.5">
                            <x-dynamic-component :component="$assistant->icon()" class="size-4 shrink-0 text-gray-900 dark:text-white"/>
                            <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $name }}</span>
                            <span class="ml-auto inline-flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                <span class="size-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                                {{ __('Relaticle connected') }}
                            </span>
                        </div>

                        <div class="space-y-4 px-4 py-5 sm:px-5">
                            <div class="flex justify-end">
                                <p class="max-w-[85%] rounded-2xl rounded-br-md bg-gray-100 px-3.5 py-2.5 text-sm leading-relaxed text-gray-900 dark:bg-white/10 dark:text-gray-100">
                                    @if($mentionPrefix !== '')
                                        <span class="font-semibold text-primary dark:text-primary-400">{{ trim($mentionPrefix) }}</span>
                                    @endif
                                    {{ $ask['prompt'] }}
                                </p>
                            </div>

                            <div class="space-y-3">
                                <p class="flex flex-wrap items-center gap-1.5">
                                    @foreach($ask['tools'] as $tool)
                                        <span class="border-rail inline-flex items-center gap-1.5 rounded-md border bg-tint px-2 py-1 text-xs text-gray-600 dark:text-gray-300">
                                            <x-lucide-plug class="size-3.5 shrink-0 text-primary dark:text-primary-400"/>
                                            {{ __('Relaticle') }}
                                            <span aria-hidden="true">·</span>
                                            {{ $tool }}
                                            <x-lucide-check class="size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400"/>
                                        </span>
                                    @endforeach
                                </p>

                                <p class="text-sm leading-relaxed text-gray-700 dark:text-gray-300">{{ $ask['reply'] }}</p>

                                @if($key === 'pipeline')
                                    <table class="w-full text-sm">
                                        <thead>
                                            <tr class="text-xs text-gray-500 dark:text-gray-400">
                                                <th scope="col" class="pb-2 text-left font-medium">{{ __('Stage') }}</th>
                                                <th scope="col" class="pb-2 text-right font-medium">{{ __('Opportunities') }}</th>
                                                <th scope="col" class="pb-2 pl-4 text-right font-medium">{{ __('Value') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-rail border-rail divide-y border-t">
                                            @foreach($stages as [$stage, $count, $value])
                                                <tr>
                                                    <td class="py-2 text-gray-700 dark:text-gray-300">{{ $stage }}</td>
                                                    <td class="py-2 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ $count }}</td>
                                                    <td class="py-2 pl-4 text-right font-medium tabular-nums text-gray-900 dark:text-white">{{ $value }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                @endif
                            </div>
                        </div>
                    </x-cloud::marketing.window>

                    @isset($ask['record'])
                        @php [$recordList, $recordTitle, $recordChips] = $ask['record']; @endphp
                        <x-cloud::marketing.window class="relative z-10 -mt-5 ml-auto w-[92%]" data-assistant-record>
                            <div class="border-rail flex items-center gap-1.5 border-b py-2 pl-4 pr-3 text-xs text-gray-500 dark:text-gray-400">
                                <x-brand.logomark class="h-3.5!"/>
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{ __('Relaticle') }}</span>
                                <span aria-hidden="true">·</span>
                                {{ $recordList }}
                                <span class="ml-auto">{{ __('Just now') }}</span>
                                <x-cloud::marketing.actor :as="$actorKey"/>
                            </div>
                            <div class="flex items-center gap-3 px-4 py-3">
                                <span class="size-4 shrink-0 rounded-full border-2 border-gray-300 dark:border-white/20" aria-hidden="true"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $recordTitle }}</p>
                                    <div class="mt-1.5 flex min-w-0 items-center gap-1.5 overflow-hidden text-xs">
                                        @foreach($recordChips as [$chipLabel, $chipIcon])
                                            <span @class([
                                                'inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5',
                                                'bg-amber-50 font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' => $chipIcon !== null,
                                                'min-w-0 truncate bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300' => $chipIcon === null,
                                            ])>
                                                @if($chipIcon)
                                                    <x-dynamic-component :component="$chipIcon" class="size-3"/>
                                                @endif
                                                {{ $chipLabel }}
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </x-cloud::marketing.window>
                    @endisset
                </div>
            @endforeach
        </div>
    </div>

    <figcaption class="mt-5 text-center text-xs text-gray-600 dark:text-gray-400">
        {{ __('An example exchange. :name answers from the records in your own workspace.', ['name' => $name]) }}
    </figcaption>
</figure>
